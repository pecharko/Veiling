<?php
declare(strict_types=1);
// Offline source contract for the three publication statuses.
$root=dirname(__DIR__);
$sellers=file_get_contents($root.'/private/lot_sellers.php');
$list=file_get_contents($root.'/catalogus-public/admin/verkopers.php');
$public=file_get_contents($root.'/catalogus-public/kunstenaars.php');
$editor=file_get_contents($root.'/catalogus-public/admin/verkoper.php');
$css=file_get_contents($root.'/catalogus-public/admin/admin.css');
function require_artist_status(bool $ok,string $label): void {
  if (!$ok) throw new RuntimeException($label);
}
require_artist_status(str_contains($sellers,"in_array(\$status,[0,1,2],true)"),'Three statuses must be validated server-side');
require_artist_status(str_contains($sellers,"seller_can_publish_artist(\$role)"),'Role authorization must remain server-side');
require_artist_status(str_contains($sellers,"\$data['artist_published']=\$data['seller_type']==='kunstenaar' ? 2 : 0;"),'New artists must start in state 2');
require_artist_status(str_contains($list,"['new','published','unpublished']"),'Admin must accept exactly three statuses');
require_artist_status(str_contains($list,"seller_can_publish_artist(effective_admin_role())"),'Only publication roles see controls');
require_artist_status(str_contains($public,'s.artist_published=1'),'Only published artists appear publicly');
require_artist_status(str_contains($editor,"?'Nieuw':'Niet gepubliceerd'"),'Editor must display new status');
foreach (['is-published','is-unpublished','is-new'] as $state) {
  require_artist_status(str_contains($list,$state) && str_contains($css,$state),'Missing status label/color '.$state);
}
echo "Artist three-state contract OK\n";
