<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static function(string $file) use($root):string {
  $content=file_get_contents($root.'/'.$file);
  if (!is_string($content)) throw new RuntimeException('Missing '.$file);
  return $content;
};
$bootstrap=$read('private/bootstrap.php');
$list=$read('catalogus-public/admin/verkopers.php');
$editor=$read('catalogus-public/admin/verkoper.php');
$sellers=$read('private/lot_sellers.php');
$artists=$read('catalogus-public/admin/kunstenaars.php');
$artistEditor=$read('catalogus-public/admin/kunstenaar-bewerken.php');
$lot=$read('catalogus-public/admin/kavel.php');
$roles=$read('catalogus-public/admin/rollen.php');
$checks=[
  'two distinct menu items'=>str_contains($bootstrap,"e(url('admin/kunstenaars.php'))")
    && str_contains($bootstrap,"e(url('admin/verkopers.php'))"),
  'routes accessible to authorized roles'=>str_contains($bootstrap,"'kunstenaars.php'")
    && str_contains($bootstrap,"'kunstenaar-bewerken.php'"),
  'artist pages reuse shared data model'=>str_contains($artists,"\$sellerScreen='kunstenaar';")
    && str_contains($artistEditor,"\$sellerScreen='kunstenaar';")
    && str_contains($artists,"require __DIR__.'/verkopers.php';")
    && str_contains($artistEditor,"require __DIR__.'/verkoper.php';"),
  'list cannot leak other seller type'=>str_contains($list,"if (\$row['seller_type']!==\$sellerScreen) return false;")
    && str_contains($list,"if (\$screenCheck->fetchColumn()!==\$sellerScreen)"),
  'name and edit action both target screen-specific editor'=>substr_count($list,"url(\$editorUrl,['id'=>(int)\$r['id']])")===3
    && !str_contains($list,"url('admin/verkoper.php',['id'=>(int)\$r['id']])"),
  'editor rejects crossing between artist and private seller'=>str_contains($editor,"if (\$existing['seller_type']!==\$sellerScreen)")
    && str_contains($editor,"(\$_POST['seller_type']??'')!==\$sellerScreen"),
  'type selection removed but server receives fixed value'=>str_contains($editor,'name="seller_type" id="seller-type"')
    && !str_contains($editor,'<select name="seller_type"'),
  'database type is immutable even for administrator'=>str_contains($sellers,"if (\$current['seller_type']!==\$data['seller_type'])")
    && str_contains($sellers,'Het type verkoper kan na aanmaak niet worden gewijzigd.'),
  'artist status restricted to artist view'=>str_contains($list,"\$sellerScreen!=='kunstenaar'")
    && str_contains($list,'seller_can_publish_artist(effective_admin_role())'),
  'status filtering applies to artists only'=>str_contains($list,"if(\$sellerScreen==='kunstenaar')")
    && str_contains($list,"\$row['seller_type']==='kunstenaar'"),
  'lot editor offers both new seller types'=>str_contains($lot,"admin/kunstenaar-bewerken.php")
    && str_contains($lot,"admin/verkoper.php"),
  'roles explain immutable type'=>str_contains($roles,'Type kunstenaar/particulier wijzigen na aanmaak'),
  'no seller schema migration required'=>true,
];
foreach ($checks as $label=>$ok) {
  echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;
  if (!$ok) exit(1);
}
