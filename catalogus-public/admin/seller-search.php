<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { http_response_code(405); echo '{"error":"Methode niet toegestaan"}'; exit; }
$pdo=db();
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$type=in_array(($_GET['type']??''),['kunstenaar','particulier'],true)?(string)$_GET['type']:'';
$query=mb_substr(trim((string)($_GET['q']??'')),0,100);
try {
  if ($id!==false && $id!==null) {
    $st=$pdo->prepare('SELECT id,name,artist_name,seller_type FROM lot_sellers WHERE id=? LIMIT 1');
    $st->execute([(int)$id]);
  } else {
    $sql='SELECT id,name,artist_name,seller_type FROM lot_sellers WHERE 1=1';
    $params=[];
    if ($type!=='') { $sql.=' AND seller_type=?'; $params[]=$type; }
    if ($query!=='') {
      $sql.=" AND (name LIKE ? OR (seller_type='kunstenaar' AND artist_name LIKE ?))";
      $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
      $sql=str_replace(' LIKE ?', " LIKE ? ESCAPE '!'", $sql);
      $params[]=$pattern; $params[]=$pattern;
    }
    $sql.=' ORDER BY COALESCE(NULLIF(artist_name,\'\'),name),id LIMIT 25';
    $st=$pdo->prepare($sql);
    $st->execute($params);
  }
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);
  $items=[];
  foreach ($rows as $row) {
    $items[]=['id'=>(int)$row['id'],'name'=>(string)($row['seller_type']==='kunstenaar' ? ($row['artist_name']?:$row['name']) : $row['name']),'type'=>(string)$row['seller_type']];
  }
  echo json_encode(['items'=>$items],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (PDOException $ex) {
  error_log('[Seller search] Query failed');
  http_response_code(503);
  echo '{"error":"Verkopers zoeken is tijdelijk niet beschikbaar."}';
}
