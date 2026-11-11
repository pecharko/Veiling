<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$lotEditor=file_get_contents($root.'/catalogus-public/admin/kavel.php');
$lotAudit=file_get_contents($root.'/private/lot_audit.php');
$lotMigration=file_get_contents($root.'/database/migrations/20261111_lot_audit.sql');
$lotForm=file_get_contents($root.'/private/lot_form.php');
$sellerLib=file_get_contents($root.'/private/lot_sellers.php');
$sellerList=file_get_contents($root.'/catalogus-public/admin/verkopers.php');
$sellerEditor=file_get_contents($root.'/catalogus-public/admin/verkoper.php');
$roles=file_get_contents($root.'/catalogus-public/admin/rollen.php');
$snapshot=file_get_contents($root.'/private/snapshots.php');
$preview=file_get_contents($root.'/dev/preview/seed.php');
foreach(compact('lotEditor','lotAudit','lotMigration','lotForm','sellerLib','sellerList','sellerEditor','roles','snapshot','preview') as $name=>$source)
  if(!is_string($source)) throw new RuntimeException('Missing source: '.$name);
foreach(['ADD COLUMN last_edited_by_admin_id','ADD COLUMN last_edited_at'] as $term)
  if(!str_contains($lotMigration,$term))throw new RuntimeException('Lot audit migration missing '.$term);
foreach(['last_edited_by_admin_id','last_edited_at','41lokeren-v7-lot-audit'] as $term)
  if(!str_contains($snapshot,$term))throw new RuntimeException('Snapshot missing '.$term);
foreach(['lot_effective_fields_changed($lot,$values)','lot_audit_touch($pdo,$id',
  'Laatste wijziging:','Aangemaakt door:','lot_audit_label('] as $term)
  if(!str_contains($lotEditor,$term))throw new RuntimeException('Lot audit UI/write missing '.$term);
if(!str_contains($lotForm,'function lot_effective_fields_changed('))
  throw new RuntimeException('No-op comparison missing');
if(!str_contains($lotAudit,'UTC_TIMESTAMP()'))
  throw new RuntimeException('Explicit audit timestamp missing');
if(!str_contains($preview,'20261111_lot_audit.sql'))
  throw new RuntimeException('Isolated preview lot audit migration missing');
foreach(['function seller_can_edit(','seller_require_edit','seller_can_edit($role,$actorId,$current)',
  'seller_can_edit($role,$actorId,$record)'] as $term)
  if(!str_contains($sellerLib,$term))throw new RuntimeException('Seller ownership protection missing '.$term);
foreach(['seller_require_edit($pdo','seller_can_edit(effective_admin_role()'] as $term)
  if(!str_contains($sellerEditor.$sellerList,$term)) throw new RuntimeException('Seller view/write guard missing '.$term);
foreach(['Eigen aangemaakte verkopers','Verkopers van andere gebruikers bewerken of verwijderen'] as $term)
  if(!str_contains($roles,$term))throw new RuntimeException('Role documentation missing '.$term);
require_once $root.'/private/lot_sellers.php';
$other=['created_by_admin_id'=>20];
if(seller_can_edit('assistant',10,$other)
  || !seller_can_edit('assistant',20,$other)
  || !seller_can_edit('veilingmeester',10,$other)
  || !seller_can_edit('administrator',10,$other))
  throw new RuntimeException('Seller ownership helper gave incorrect permission');
echo "PASS lot audit and assistant-only-own-seller rights contract.\n";
