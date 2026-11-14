<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/lot_sellers.php';
function artist_role_assert(bool $ok,string $what): void {
  if (!$ok) throw new RuntimeException('Artist role permissions: '.$what);
}
$draft=['seller_type'=>'kunstenaar','artist_published'=>0,'created_by_admin_id'=>7];
$published=['seller_type'=>'kunstenaar','artist_published'=>1,'created_by_admin_id'=>7];
$private=['seller_type'=>'particulier','artist_published'=>0,'created_by_admin_id'=>7];
artist_role_assert(seller_can_edit('assistant',7,$draft),'creator can edit unpublished artist');
artist_role_assert(!seller_can_edit('assistant',8,$draft),'other assistant cannot edit draft');
artist_role_assert(!seller_can_edit('assistant',7,$published),'creator cannot edit published artist');
artist_role_assert(!seller_can_edit('assistant',8,$published),'other assistant cannot edit published artist');
artist_role_assert(seller_can_edit('administrator',8,$published),'administrator can edit published artist');
artist_role_assert(seller_can_edit('veilingmeester',8,$published),'auctioneer can edit published artist');
artist_role_assert(seller_can_edit('assistant',7,$private),'creator can edit private seller');
artist_role_assert(!seller_can_publish_artist('assistant'),'assistant cannot change publication');
artist_role_assert(seller_can_publish_artist('administrator') && seller_can_publish_artist('veilingmeester'),'publication roles');
echo "PASS published artist write restrictions by role\n";
