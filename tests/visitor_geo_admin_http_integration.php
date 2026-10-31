<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';
require dirname(__DIR__).'/private/visitor_geo_snapshot.php';
if(getenv('GITHUB_ACTIONS')!=='true'||cfg('setup_key')!=='ci-test-only-nonproduction'
    ||(cfg('database')['host']??'')!=='127.0.0.1'||db()->query('SELECT DATABASE()')->fetchColumn()!=='auction_test')throw new RuntimeException('Only localhost CI HTTP fixture is permitted');
$pdo=db();$jar=tempnam(sys_get_temp_dir(),'geo-admin-cookie-');$checks=0;
function gh_check(bool $yes,string $message): void {global $checks;if(!$yes)throw new RuntimeException($message);$checks++;}
function gh_request(string $path,?array $data=null): array {
    global $jar;
    $curl=curl_init('http://localhost:8090'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>15]);
    if($data!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if($body===false)throw new RuntimeException('Local HTTP fixture unavailable');
    return [$status,(string)$body];
}
function gh_token(string $html,string $field='csrf'): string {
    if(!preg_match('/name="'.preg_quote($field,'/').'" value="([a-f0-9]+)"/',$html,$match))throw new RuntimeException('Expected form token missing');
    return $match[1];
}
function gh_geo_html(string $html): string {
    if(!preg_match('/<section[^>]*aria-labelledby="geo-title".*?<\/section>/s',$html,$match))throw new RuntimeException('Geo panel missing');
    return $match[0];
}
try {
    foreach(array_keys(GEO_SNAPSHOT_COLUMNS) as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
    foreach(['database/visitor_stats.sql','database/visitor_stats_aggregates.sql','database/migrations/20261029_visitor_geo_analytics.sql'] as $path) {
        $sql=preg_replace('/^\s*--[^\n]*$/m','',(string)file_get_contents(dirname(__DIR__).'/'.$path));
        foreach(explode(';',(string)$sql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    }
    [$status]=gh_request('/admin/statistieken.php');gh_check($status===303,'Anonymous geography panel requires login');
    [$status,$html]=gh_request('/admin/login.php');
    [$status]=gh_request('/admin/login.php',['csrf'=>gh_token($html),'email'=>'ci-admin@example.invalid','password'=>'ci-password-not-a-real-secret']);
    gh_check($status===303,'CI administrator login succeeds');
    [$status,$html]=gh_request('/admin/statistieken.php');
    gh_check($status===200&&str_contains(gh_geo_html($html),'nog niet ingeschakeld'),'Default-OFF panel is visible without activation');
    $day=gmdate('Y-m-d');$pdo->prepare('UPDATE visitor_geo_state SET start_date=?')->execute([$day]);
    for($n=0;$n<6;$n++) {
        $id=hash('sha256','http-geo-'.$n,true);
        $pdo->prepare("INSERT INTO visitor_stats_daily VALUES (?,?,'home',0,1)")->execute([$day,$id]);
        $pdo->prepare("INSERT INTO visitor_geo_daily VALUES (?,?,'BE','VOV','Lokeren',1)")->execute([$day,$id]);
    }
    [$status,$html]=gh_request('/admin/statistieken.php');$panel=gh_geo_html($html);$csrf=gh_token($html);
    gh_check($status===200&&str_contains($panel,'100,0%')&&str_contains($panel,'Lokeren · Oost-Vlaanderen'),'Real authenticated panel renders geographic percentages and labels');
    gh_check(!str_contains($panel,'visitor_id')&&!str_contains($panel,'visitor_days'),'Panel exposes no raw identifiers or internal counts');
    $pdo->exec('UPDATE visitor_geo_daily SET views=2');
    [, $html]=gh_request('/admin/statistieken.php');$panel=gh_geo_html($html);
    gh_check(str_contains($panel,'onvolledig')&&!str_contains($panel,'<table'),'Real panel hides misleading partial distributions');
    $pdo->exec('UPDATE visitor_geo_daily SET views=1');
    [$status]=gh_request('/admin/statistieken.php',['action'=>'confirm_delete','confirmation'=>'VERWIJDER']);
    gh_check($status===403&&(int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===6,'Missing CSRF cannot delete geo');
    [, $html]=gh_request('/admin/statistieken.php',['csrf'=>$csrf,'action'=>'preview_delete','scope'=>'all']);
    gh_check((int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===6,'Preview is read-only');
    $preview=gh_token($html,'preview_token');
    gh_request('/admin/statistieken.php',['csrf'=>$csrf,'action'=>'confirm_delete','preview_token'=>$preview,'confirmation'=>'wrong']);
    gh_check((int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===6,'Wrong confirmation preserves geo detail');
    [, $html]=gh_request('/admin/statistieken.php',['csrf'=>$csrf,'action'=>'preview_delete','scope'=>'all']);
    [$status]=gh_request('/admin/statistieken.php',['csrf'=>$csrf,'action'=>'confirm_delete','preview_token'=>gh_token($html,'preview_token'),'confirmation'=>'VERWIJDER']);
    gh_check($status===303&&(int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn()===0,'Authorized two-step deletion removes both datasets');
    [, $html]=gh_request('/admin/statistieken.php');gh_check(str_contains(gh_geo_html($html),'Nog geen geografische gegevens'),'Real panel has honest empty state after deletion');
} finally {
    if(is_string($jar)&&is_file($jar))unlink($jar);
    foreach(array_keys(GEO_SNAPSHOT_COLUMNS) as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
}
echo "PASS $checks geographic admin HTTP checks\n";
