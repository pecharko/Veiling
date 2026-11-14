<?php
declare(strict_types=1);
// Non-mutating contract for the admin list pagination and artist-publication return.
$root=dirname(__DIR__);
$read=static function(string $path) use ($root):string {
  $result=file_get_contents($root.'/'.$path);
  if ($result===false) throw new RuntimeException('Missing '.$path);
  return $result;
};
$lots=$read('catalogus-public/admin/index.php');
$sellers=$read('catalogus-public/admin/verkopers.php');
$catalogCss=$read('catalogus-public/assets/catalogus.css');
$adminCss=$read('catalogus-public/admin/admin.css');
$checks=[
  'lot page sizes whitelist'=>str_contains($lots,"[20,50,100]") && str_contains($lots,'name="per_page"'),
  'lot query remains database paginated'=>str_contains($lots,'LIMIT ? OFFSET ?') && str_contains($lots,'PDO::PARAM_INT'),
  'lot filter and page retained'=>str_contains($lots,'$p=$_GET') && str_contains($lots,"$p['pagina']"),
  'seller page sizes whitelist'=>str_contains($sellers,"[20,50,100]") && str_contains($sellers,'name="per_page"'),
  'seller separate group pages'=>str_contains($sellers,"'pagina_'."."$"."groupType") && str_contains($sellers,'array_slice($allGroupRows'),
  'seller pagination retains query'=>str_contains($sellers,'$params=$_GET') && str_contains($sellers,'seller_page_url('),
  'artist publication retains page and row'=>str_contains($sellers,'#seller-row-') && str_contains($sellers,'$returnParams[$key]'),
  'public grid has four desktop columns'=>str_contains($catalogCss,'.artists-overview-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))'),
  'public grid preserves tablet and phone breakpoints'=>str_contains($catalogCss,'@media(max-width:900px){.artists-overview-grid{grid-template-columns:repeat(2,minmax(0,1fr))}')
    && str_contains($catalogCss,'@media(max-width:580px){.artists-overview{'),
  'seller row anchored below header'=>str_contains($adminCss,'scroll-margin-top:110px'),
];
foreach($checks as $label=>$ok) {
  echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;
  if(!$ok) exit(1);
}
