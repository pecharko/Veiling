<?php
declare(strict_types=1);
require_once __DIR__ . '/visitor_geo_state.php';
require_once __DIR__ . '/visitor_geo_report.php';
require_once __DIR__ . '/visitor_geo_render.php';

/** One consistent read view for report and coverage; no writes. */
function visitor_geo_dashboard(PDO $pdo, ?int $days = 30): array
{
    if ($days!==null && ($days<1 || $days>3660)) throw new InvalidArgumentException('Invalid dashboard range');
    if ($pdo->inTransaction()) throw new LogicException('Geo dashboard requires its own read transaction');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    try {
        $state=visitor_geo_state($pdo);
        $today=new DateTimeImmutable('today',new DateTimeZone('UTC'));
        if (!visitor_geo_covers_day($state,$today->format('Y-m-d'))) {
            $pdo->commit();return ['status'=>'inactive','since'=>$state['start_date']??null,'rows'=>[]];
        }
        $since=$days===null?$state['start_date']:max($state['start_date'],$today->modify('-'.($days-1).' days')->format('Y-m-d'));
        $rows=visitor_geo_report($pdo,$days,$since);
        $stmt=$pdo->prepare('SELECT COALESCE(SUM(visitor_days),0) visitors,COALESCE(SUM(views),0) views FROM (
            SELECT COUNT(DISTINCT visitor_id) visitor_days,COALESCE(SUM(views),0) views FROM visitor_stats_daily WHERE visit_date BETWEEN ? AND ? GROUP BY visit_date
            UNION ALL SELECT visitor_days,views FROM visitor_stats_day_totals WHERE visit_date BETWEEN ? AND ?
        ) all_days');
        $stmt->execute([$since,$today->format('Y-m-d'),$since,$today->format('Y-m-d')]);
        $normal=$stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $covered=(int)$normal['visitors']===(int)array_sum(array_column($rows,'visitor_days'))
            && (int)$normal['views']===(int)array_sum(array_column($rows,'views'));
        $pdo->commit();return ['status'=>$covered?'ready':'partial','since'=>$since,'rows'=>$covered?$rows:[]];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
}

/** Suppress names below five visitor-days, keeping unknown and the denominator. */
function visitor_geo_display_percentages(array $rows, string $level): array
{
    if ($level!=='country') $rows=array_values(array_filter($rows,static fn($r)=>$r['country_code']==='BE'));
    $safe=[];$small=0;
    foreach (visitor_geo_group($rows,$level) as $group) {
        $unknown=$group['label']==='ZZ' || str_ends_with($group['label'],'/?');
        if ($group['visitor_days']<5 && !$unknown) { $small+=$group['visitor_days'];continue; }
        $safe[]=['label'=>visitor_geo_display_label($group['label'],$level),'visitor_days'=>$group['visitor_days']];
    }
    if ($small>0) $safe[]=['label'=>'Overige (kleine groepen)','visitor_days'=>$small];
    return visitor_geo_percentages($safe);
}

function visitor_geo_display_label(string $label, string $level): string
{
    $parts=explode('/',$label);
    if ($level==='country') return ['BE'=>'België','NL'=>'Nederland','FR'=>'Frankrijk','DE'=>'Duitsland','GB'=>'Verenigd Koninkrijk','US'=>'Verenigde Staten','ZZ'=>'Onbekend'][$label]??$label;
    $region=['VOV'=>'Oost-Vlaanderen','VAN'=>'Antwerpen','VBR'=>'Vlaams-Brabant','VLI'=>'Limburg','VWV'=>'West-Vlaanderen','WBR'=>'Waals-Brabant','WHT'=>'Henegouwen','WLG'=>'Luik','WLX'=>'Luxemburg','WNA'=>'Namen','BRU'=>'Brussel'][$parts[1]??'']??($parts[1]??'?');
    if ($level==='region') return $region==='?'?'Onbekende provincie/regio':$region;
    $city=implode('/',array_slice($parts,2));
    return $city==='?'?'Onbekende stad':$city.' · '.($region==='?'?'onbekende regio':$region);
}

function visitor_geo_render_dashboard(array $reports): string
{
    $html='<section class="dashboard-panel" aria-labelledby="geo-title"><h2 id="geo-title">Geografische herkomst</h2>';
    $html.='<p>Indicatieve herkomst op basis van het IP-adres, geen exacte locatie. De percentages gaan over bezoekersdagen, niet noodzakelijk unieke personen. Kleine groepen worden samengevoegd. Provincies en steden tonen uitsluitend de verdeling binnen België.</p>';
    foreach ($reports as $title=>$report) {
        $html.='<h3>'.htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</h3>';
        if ($report['status']==='inactive') { $html.='<p>Geografische registratie is nog niet ingeschakeld.</p>';continue; }
        if ($report['status']==='error') { $html.='<p>Geografische gegevens zijn tijdelijk niet beschikbaar.</p>';continue; }
        if ($report['status']==='partial') { $html.='<p>De geografische registratie is onvolledig voor deze periode. Percentages worden daarom niet getoond.</p>';continue; }
        $html.='<p>Beschikbare registratie vanaf '.htmlspecialchars((string)$report['since'],ENT_QUOTES,'UTF-8').' (UTC). Oudere bezoeken hebben geen geografische gegevens.</p>';
        foreach (['country'=>'Landen','region'=>'Belgische provincies/regio’s','city'=>'Belgische steden'] as $level=>$label) {
            $html.=visitor_geo_render_percentage_table(visitor_geo_display_percentages($report['rows'],$level),$label);
        }
    }
    return $html.'</section>';
}
