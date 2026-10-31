<?php
declare(strict_types=1);
require_once __DIR__ . '/visitor_geo_state.php';

const GEO_SNAPSHOT_COLUMNS = [
    'visitor_stats_daily'=>['visit_date','visitor_id','page_kind','lot_id','views'],
    'visitor_stats_day_totals'=>['visit_date','visitor_days','views','lot_views'],
    'visitor_stats_lot_totals'=>['visit_date','lot_id','visitor_days','views'],
    'visitor_geo_daily'=>['visit_date','visitor_id','country_code','region_code','city_name','views'],
    'visitor_geo_day_totals'=>['visit_date','country_code','region_code','city_name','visitor_days','views'],
    'visitor_geo_state'=>['id','start_date'],
];

/** Called inside the snapshot's consistent read view; never starts/commits it. */
function visitor_geo_snapshot_export(PDO $pdo): ?array
{
    if (!$pdo->inTransaction()) throw new LogicException('Analytics snapshot requires a transaction');
    if (visitor_geo_state($pdo) === null) return null;
    $tables = [];$totalRows=0;
    foreach (GEO_SNAPSHOT_COLUMNS as $table=>$columns) {
        $limit=200001-$totalRows;
        $rows = $pdo->query('SELECT * FROM ' . $table.' LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
        $totalRows+=count($rows);
        if($totalRows>200000)throw new RuntimeException('Analytics snapshot exceeds row safety limit');
        foreach ($rows as &$row) {
            if (isset($row['visitor_id'])) {
                if (strlen($row['visitor_id']) !== 32) throw new RuntimeException('Invalid visitor identifier');
                $row['visitor_id'] = bin2hex($row['visitor_id']);
            }
        }
        unset($row);
        $tables[$table] = $rows;
    }
    $data = ['identifier_encoding'=>'hex32','tables'=>$tables];
    visitor_geo_snapshot_validate($data);
    if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 20*1024*1024) throw new RuntimeException('Analytics snapshot too large');
    return $data;
}

/** Reject malformed analytics before the restore can swap any photos. */
function visitor_geo_snapshot_validate(mixed $data): void
{
    if (!is_array($data) || array_keys($data)!==['identifier_encoding','tables']
        || $data['identifier_encoding']!=='hex32' || !is_array($data['tables'])
        || array_keys($data['tables'])!==array_keys(GEO_SNAPSHOT_COLUMNS)) {
        throw new RuntimeException('Ongeldige geografische snapshotinhoud.');
    }
    foreach (GEO_SNAPSHOT_COLUMNS as $table=>$columns) {
        $rows = $data['tables'][$table];
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>200000) throw new RuntimeException('Invalid analytics rows');
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row),$columns) || array_diff($columns,array_keys($row))) throw new RuntimeException('Invalid analytics columns');
            foreach ($row as $column=>$value) {
                if (in_array($column,['id','lot_id','views','visitor_days','lot_views'],true)) {
                    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D',(string)$value)) throw new RuntimeException('Invalid analytics number');
                } elseif ($column==='visitor_id') {
                    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D',$value)) throw new RuntimeException('Invalid encoded visitor identifier');
                } elseif ($column==='visit_date' || $column==='start_date') {
                    if ($column==='start_date' && $value===null) continue;
                    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC')) : false;
                    if (!$date || $date->format('Y-m-d')!==$value) throw new RuntimeException('Invalid analytics date');
                } elseif ($column==='page_kind') {
                    if (!in_array($value,['home','lot'],true)) throw new RuntimeException('Invalid analytics page kind');
                } elseif (!is_string($value)) throw new RuntimeException('Invalid geographic value');
            }
            if (isset($row['country_code'])) {
                if (!preg_match('/^[A-Z]{2}$/D',$row['country_code'])
                    || ($row['region_code']!=='' && !preg_match('/^[A-Z0-9-]{1,16}$/D',$row['region_code']))
                    || !mb_check_encoding($row['city_name'],'UTF-8') || mb_strlen($row['city_name'],'UTF-8')>120
                    || preg_match('/[\x00-\x1F\x7F]/u',$row['city_name'])
                    || ($row['country_code']!=='BE' && $row['city_name']!=='')
                    || ($row['country_code']==='ZZ' && $row['region_code']!=='')) throw new RuntimeException('Invalid geographic bucket');
            }
            $keys = array_values(array_diff($columns,['views','visitor_days','lot_views','start_date']));
            // Geographic detail is keyed only by date and visitor, not location.
            if ($table==='visitor_geo_daily') $keys=['visit_date','visitor_id'];
            $key=json_encode(array_map(static fn($k)=>(string)$row[$k],$keys),JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) throw new RuntimeException('Duplicate analytics identity');
            $seen[$key]=true;
        }
    }
    $state=$data['tables']['visitor_geo_state'];
    if (count($state)!==1 || (int)$state[0]['id']!==1) throw new RuntimeException('Invalid geographic singleton');
}

function visitor_geo_snapshot_restore(PDO $pdo, array $data): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Analytics restore requires parent transaction');
    visitor_geo_snapshot_validate($data);
    foreach (array_reverse(array_keys(GEO_SNAPSHOT_COLUMNS)) as $table) $pdo->exec('DELETE FROM ' . $table);
    foreach (GEO_SNAPSHOT_COLUMNS as $table=>$columns) {
        $stmt=$pdo->prepare('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        foreach ($data['tables'][$table] as $row) {
            if (isset($row['visitor_id'])) $row['visitor_id']=hex2bin($row['visitor_id']);
            $stmt->execute(array_map(static fn($col)=>$row[$col],$columns));
        }
    }
}

/** Stable read-only recovery fingerprint, without exposing any row contents. */
function visitor_geo_snapshot_digest(?array $data): string
{
    if ($data===null) return hash('sha256','no_geo_schema');
    visitor_geo_snapshot_validate($data);
    $normalized=[];
    foreach ($data['tables'] as $table=>$rows) {
        $encoded=[];
        foreach ($rows as $row) {
            ksort($row);
            $row=array_map(static fn($v)=>$v===null?null:(string)$v,$row);
            $encoded[]=json_encode($row,JSON_THROW_ON_ERROR);
        }
        sort($encoded,SORT_STRING);$normalized[$table]=$encoded;
    }
    return hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
}
