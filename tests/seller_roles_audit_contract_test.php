<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$bootstrap=file_get_contents($root.'/private/bootstrap.php');
$helpers=file_get_contents($root.'/private/lot_sellers.php');
$overview=file_get_contents($root.'/catalogus-public/admin/verkopers.php');
$editor=file_get_contents($root.'/catalogus-public/admin/verkoper.php');
$migration=file_get_contents($root.'/database/migrations/20261111_seller_audit.sql');
$snapshot=file_get_contents($root.'/private/snapshots.php');
$preview=file_get_contents($root.'/dev/preview/seed.php');
foreach (compact('bootstrap','helpers','overview','editor','migration','snapshot','preview') as $key=>$value)
  if (!is_string($value)) throw new RuntimeException('Missing audit source: '.$key);
foreach (['verkopers.php','verkoper.php'] as $page) {
  if (!str_contains($bootstrap,"'".$page."'")) throw new RuntimeException('Role allowlist missing '.$page);
}
if (!str_contains($bootstrap,"'<a href=\"' . e(url('admin/verkopers.php'))"))
  throw new RuntimeException('Seller menu not exposed to all roles');
if (!str_contains($overview,'require_auth()') || !str_contains($editor,'require_auth()'))
  throw new RuntimeException('Private seller pages require authenticated role');
foreach (['created_by_admin_id','updated_by_admin_id','created_at','updated_at'] as $field) {
  if (!str_contains($migration,'ADD COLUMN '.$field)) throw new RuntimeException('Audit SQL missing '.$field);
  if (!str_contains($snapshot,"'".$field."'")) throw new RuntimeException('Audit snapshot missing '.$field);
}
foreach (['UTC_TIMESTAMP()','updated_by_admin_id=?','created_by_admin_id','created_at'] as $key)
  if (!str_contains($helpers,$key)) throw new RuntimeException('Audit writer missing '.$key);
if (!str_contains($editor,"(int)\$_SESSION['admin_id']"))
  throw new RuntimeException('Audit actor must originate from the authenticated session');
if (!str_contains($editor,"$" . "audit") || !str_contains($editor,'Aangemaakt door:')
  || !str_contains($editor,'Laatste wijziging:'))
  throw new RuntimeException('Seller edit audit display missing');
if (!str_contains($overview,'Aangemaakt door / op') || !str_contains($overview,'Laatst gewijzigd door / op'))
  throw new RuntimeException('Seller overview audit columns missing');
if (!str_contains($preview,'20261111_seller_audit.sql'))
  throw new RuntimeException('Isolated preview audit migration missing');
require_once $root.'/private/lot_sellers.php';
foreach (['administrator','assistant','veilingmeester'] as $role) {
  if (!seller_can_manage($role)) throw new RuntimeException('Seller permission missing '.$role);
}
if (seller_can_manage('untrusted-role')) throw new RuntimeException('Unrecognized role allowed');
echo "PASS private seller access for all roles, authenticated audit writers, and snapshot scope.\n";
