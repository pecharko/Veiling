<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/auction_organisation.php';
function verify_order(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$original = [11,22,33,44,55];
verify_order(auction_move_ids($original,44,2)===[11,44,22,33,55],'move backwards shifts intermediate lots');
verify_order(auction_move_ids($original,22,5)===[11,33,44,55,22],'move forwards shifts intermediate lots');
verify_order(auction_move_ids($original,33,3)===$original,'same position unchanged');
verify_order(auction_move_ids($original,11,5)===[22,33,44,55,11],'first to last');
verify_order(auction_move_ids($original,55,1)===[55,11,22,33,44],'last to first');
foreach ([[99,1],[11,0],[11,6]] as [$id,$position]) {
  try { auction_move_ids($original,$id,$position); throw new RuntimeException('Unsafe move accepted'); }
  catch (InvalidArgumentException $expected) {}
}
try { auction_move_ids([1,1],1,1); throw new RuntimeException('Duplicate input accepted'); }
catch (InvalidArgumentException $expected) {}
$screen=file_get_contents(dirname(__DIR__).'/catalogus-public/admin/veiling-organisatie.php');
$bootstrap=file_get_contents(dirname(__DIR__).'/private/bootstrap.php');
verify_order(str_contains($screen,'check_csrf()'),'CSRF must be checked');
verify_order(str_contains($screen,'catalog_lock_for_request($pdo)'),'catalog lock required');
verify_order(str_contains($screen,'$pdo->beginTransaction()'),'transaction required');
verify_order(str_contains($screen,'$pdo->rollBack()'),'rollback on failure required');
verify_order(str_contains($screen,'order_version'),'stale form guard required');
verify_order(str_contains($screen,'auction_apply_order($pdo, $ordered)'),'transactional renumber');
verify_order(str_contains($screen,'draggable="true"'),'drag and drop present');
verify_order(str_contains($bootstrap,'Veiling organisatie'),'menu entry exists');
verify_order(str_contains($bootstrap,"'veiling-organisatie.php'"),'limited role has access');
echo "PASS auction organisation permutation and page security contracts\n";
