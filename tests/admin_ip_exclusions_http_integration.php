<?php
declare(strict_types=1);
/** Actual HTTP, separate source IPs, auth/CSRF and isolated MySQL. */
require dirname(__DIR__).'/private/bootstrap.php';
require_once dirname(__DIR__).'/private/analytics_control.php';
require_once dirname(__DIR__).'/private/visitor_geo_state.php';
if (getenv('GITHUB_ACTIONS') !== 'true' || cfg('setup_key') !== 'ci-test-only-nonproduction'
    || (cfg('database')['host'] ?? '') !== '127.0.0.1'
    || db()->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Only disposable localhost CI permitted');
}
$pdo=db();$jars=[];$checks=0;
function ip_check(bool $ok,string $message): void {
    global $checks;if (!$ok) throw new RuntimeException($message);$checks++;echo "PASS $message\n";
}
function ip_http(string $path,?array $data=null,string $ip='127.0.0.2',string $session='admin'): array {
    global $jars;
    if (!isset($jars[$session])) $jars[$session]=tempnam(sys_get_temp_dir(),'ip-ci-');
    $ch=curl_init('http://127.0.0.1:8091'.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEFILE=>$jars[$session],CURLOPT_COOKIEJAR=>$jars[$session],CURLOPT_INTERFACE=>$ip,
        CURLOPT_USERAGENT=>'CI genuine browser',CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['X-Forwarded-For: 203.0.113.44','X-Real-IP: 203.0.113.45']]);
    if ($data!==null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if ($body===false) throw new RuntimeException('Local IP HTTP fixture unavailable');
    return [$status,(string)$body];
}
function ip_csrf(string $html): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m)) throw new RuntimeException('Missing form CSRF');
    return $m[1];
}
function ip_login(string $ip='127.0.0.2',string $session='admin',string $email='ci-admin@example.invalid',string $password='ci-password-not-a-real-secret'): int {
    [, $form]=ip_http('/admin/login.php',null,$ip,$session);
    [$status]=ip_http('/admin/login.php',['csrf'=>ip_csrf($form),'email'=>$email,'password'=>$password],$ip,$session);
    return $status;
}
function ip_rows(): int {return (int)db()->query('SELECT COUNT(*) FROM visitor_stats_ip_exclusions')->fetchColumn();}
function ip_views(string $table='visitor_stats_daily'): int {return (int)db()->query('SELECT COALESCE(SUM(views),0) FROM '.$table)->fetchColumn();}
$oldPublished=settings()['is_published'];$enabledBefore=analytics_collection_enabled();
$adminId=(int)$pdo->query("SELECT id FROM admins WHERE email='ci-admin@example.invalid'")->fetchColumn();
$assistantId=0;$lotId=0;
try {
    set_analytics_collection_enabled(true);visitor_geo_set_enabled($pdo);
    analytics_ip_set_exclusion($pdo,true);$pdo->exec('DELETE FROM visitor_stats_ip_exclusions');
    $pdo->exec('UPDATE settings SET is_published=1 WHERE id=1');
    $pdo->prepare("INSERT INTO lots(lot_number,title,category,description,details,status) VALUES (999111,'IP fixture','CI','','','published')")->execute();
    $lotId=(int)$pdo->lastInsertId();
    ip_check(analytics_ip_exclusion_enabled($pdo),'Exclusion enabled before first login');
    ip_check(ip_login('127.0.0.2','bad','ci-admin@example.invalid','wrong')===200 && ip_rows()===0,'Failed login never stores an IP/admin pair');
    ip_check(ip_login()===303 && ip_rows()===1,'Successful login immediately stores address before any preview click');
    $row=$pdo->query('SELECT ip_address,admin_id,created_at FROM visitor_stats_ip_exclusions')->fetch();
    ip_check($row['ip_address']===analytics_ip_key('127.0.0.2') && (int)$row['admin_id']===$adminId,'Stored address is server REMOTE_ADDR with correct admin ID; spoofed headers ignored');
    [, $page]=ip_http('/admin/index.php');$csrf=ip_csrf($page);
    ip_http('/admin/logout.php',['csrf'=>$csrf]);
    ip_check(ip_login()===303 && ip_rows()===1 && $pdo->query('SELECT created_at FROM visitor_stats_ip_exclusions')->fetchColumn()===$row['created_at'],'Repeated login leaves existing pair unchanged');
    ip_check(ip_login('127.0.0.4','moved')===303 && ip_rows()===2,'New IP for same administrator is added while old address remains');
    $pdo->prepare("INSERT INTO admins(name,email,password_hash,is_active,role) VALUES ('CI IP assistant','ci-ip-assistant@example.invalid',?,1,'assistant')")
        ->execute([password_hash('ci-password-not-a-real-secret',PASSWORD_DEFAULT)]);
    $assistantId=(int)$pdo->lastInsertId();
    ip_check(ip_login('127.0.0.2','assistant','ci-ip-assistant@example.invalid')===303 && ip_rows()===3,'Two administrators sharing one address retain separate admin IDs');
    $before=ip_views();$geoBefore=ip_views('visitor_geo_daily');
    ip_http('/index.php');ip_http('/kavel.php?nr=999111');
    ip_http('/index.php',null,'127.0.0.2','anonymous-same-ip');
    ip_check(ip_views()===$before && ip_views('visitor_geo_daily')===$geoBefore,'Known IP suppresses home, lot and geo across anonymous sessions');
    ip_http('/index.php',null,'127.0.0.3','guest');
    ip_check(ip_views()===$before+1 && ip_views('visitor_geo_daily')===$geoBefore+1,'Different ordinary IP still records both visitor and geo views');
    [$status]=ip_http('/admin/statistieken.php',['action'=>'toggle_ip_exclusion','enabled'=>'0','csrf'=>'invalid']);
    ip_check($status===403 && analytics_ip_exclusion_enabled($pdo),'CSRF blocks switch mutations');
    [$status]=ip_http('/admin/statistieken.php',['action'=>'toggle_ip_exclusion','enabled'=>'0','csrf'=>'invalid'],'127.0.0.2','assistant');
    ip_check($status===403 && analytics_ip_exclusion_enabled($pdo),'Assistant cannot change administrator statistics switch');
    [, $page]=ip_http('/admin/statistieken.php');$csrf=ip_csrf($page);
    ip_check(str_contains($page,'IP-adressen uitsluiten') && str_contains($page,(string)$adminId),'Statistics module shows exclusion control and admin IDs');
    [$status,$page]=ip_http('/admin/statistieken.php',['action'=>'toggle_ip_exclusion','enabled'=>['0'],'csrf'=>$csrf]);
    ip_check($status===200 && analytics_ip_exclusion_enabled($pdo) && str_contains($page,'Ongeldige keuze voor IP-uitsluiting'),'Malformed switch value cannot change state');
    [$status]=ip_http('/admin/statistieken.php',['action'=>'toggle_ip_exclusion','enabled'=>'0','csrf'=>$csrf]);
    ip_check($status===303 && !analytics_ip_exclusion_enabled($pdo) && ip_rows()===3,'Disabling preserves every address/admin pair');
    ip_http('/index.php',['admin_preview_action'=>'start','csrf'=>$csrf]);
    $before=ip_views();$geoBefore=ip_views('visitor_geo_daily');
    [, $page]=ip_http('/index.php');ip_http('/kavel.php?nr=999111');
    ip_check(ip_views()===$before+2 && ip_views('visitor_geo_daily')===$geoBefore+2 && str_contains($page,'Statistieken kunnen worden verzameld'),'Disabled switch counts even active preview sessions and banner says so');
    ip_check(ip_login('127.0.0.5','off-login')===303 && ip_rows()===4,'Login continues registering addresses while exclusion is off');
    [$status]=ip_http('/admin/statistieken.php',['action'=>'toggle_ip_exclusion','enabled'=>'1','csrf'=>$csrf]);
    ip_check($status===303 && analytics_ip_exclusion_enabled($pdo) && ip_rows()===4,'Re-enabling reuses the complete preserved list');
    $before=ip_views();ip_http('/index.php');ip_http('/index.php',null,'127.0.0.5','off-login');
    ip_check(ip_views()===$before,'Re-enabled exclusions apply to both old and newly retained addresses');
    ip_http('/index.php',['admin_preview_action'=>'start','csrf'=>$csrf],'127.0.0.6');
    ip_check(ip_rows()===4,'Preview click from changed network never registers or changes an IP');
    $before=ip_views();ip_http('/index.php',null,'127.0.0.6');
    ip_check(ip_views()===$before+1,'Unknown address counts even if a preview session is active');
    ip_http('/admin/logout.php',['csrf'=>$csrf]);$before=ip_views();ip_http('/index.php');
    ip_check(ip_views()===$before,'Logout never removes the persistent IP exclusion');
    $pdo->exec('RENAME TABLE visitor_stats_ip_exclusions TO visitor_stats_ip_exclusions_ci_hidden');
    try {
        ip_check(ip_login('127.0.0.7','missing-table')===303,'Unavailable exclusion storage never locks an administrator out');
        $before=ip_views();ip_http('/index.php',null,'127.0.0.7','missing-table');
        ip_check(ip_views()===$before,'Unverifiable exclusion fails closed for analytics without breaking the public page');
        [$status,$page]=ip_http('/admin/statistieken.php',null,'127.0.0.7','missing-table');
        ip_check($status===200 && str_contains($page,'IP-uitsluiting is tijdelijk niet beschikbaar'),'Unavailable exclusion storage has a clear administrator status');
    } finally {
        $pdo->exec('RENAME TABLE visitor_stats_ip_exclusions_ci_hidden TO visitor_stats_ip_exclusions');
    }
} finally {
    $pdo->prepare('UPDATE settings SET is_published=? WHERE id=1')->execute([$oldPublished]);
    if ($assistantId) $pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$assistantId]);
    if ($lotId) $pdo->prepare('DELETE FROM lots WHERE id=?')->execute([$lotId]);
    $pdo->exec('DELETE FROM visitor_stats_ip_exclusions');analytics_ip_set_exclusion($pdo,true);
    set_analytics_collection_enabled($enabledBefore);
    foreach ($jars as $jar) if (is_file($jar)) unlink($jar);
}
echo "PASS $checks isolated login/IP/admin/switch/visitor/geo HTTP checks\n";
