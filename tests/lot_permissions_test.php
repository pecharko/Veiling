<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/lot_permissions.php';

function must(bool $ok, string $label): void {
  if (!$ok) throw new RuntimeException('Kavelrechten: ' . $label);
}
foreach (['assistant', 'veilingmeester', 'administrator'] as $role) {
  must(lot_can_create($role), "$role kan aanmaken");
}
must(!lot_can_create('unknown'), 'onbekende rol mag niet aanmaken');
$ownDraft = ['status'=>'draft', 'created_by_admin_id'=>42];
$otherDraft = ['status'=>'draft', 'created_by_admin_id'=>43];
$noOwnerDraft = ['status'=>'draft', 'created_by_admin_id'=>null];
$ownPublished = ['status'=>'published', 'created_by_admin_id'=>42];
must(lot_can_edit('assistant', 42, $ownDraft), 'assistent eigen concept');
must(!lot_can_edit('assistant', 42, $otherDraft), 'assistent andermans concept');
must(!lot_can_edit('assistant', 42, $noOwnerDraft), 'assistent onbekende maker');
must(!lot_can_edit('assistant', 42, $ownPublished), 'assistent eigen gepubliceerd');
foreach (['sold', 'canceled'] as $finalStatus) {
  $ownFinal = ['status'=>$finalStatus, 'created_by_admin_id'=>42];
  must(!lot_can_edit('assistant', 42, $ownFinal), "assistent kan eigen $finalStatus niet wijzigen");
  foreach (['administrator', 'veilingmeester'] as $privilegedRole) {
    must(lot_can_edit($privilegedRole, 12, $ownFinal), "$privilegedRole kan $finalStatus beheren");
  }
}
must(!lot_can_edit('assistant', 0, $ownDraft), 'assistent zonder account');
must(!lot_can_edit('unknown', 42, $ownDraft), 'onbekende rol');
foreach (['administrator', 'veilingmeester'] as $role) {
  foreach ([$ownDraft, $otherDraft, $noOwnerDraft, $ownPublished] as $lot) {
    must(lot_can_edit($role, 12, $lot), "$role beheert elk kavel");
  }
  must(lot_can_change_publication($role), "$role kan publiceren");
}
must(!lot_can_change_publication('assistant'), 'assistent kan niet publiceren');
must(lot_publication_conflict('published', 'markt', true), 'uitsluitend veiling blokkeert marktpublicatie');
must(!lot_publication_conflict('draft', 'markt', true), 'concept op markt is niet gepubliceerd');
must(!lot_publication_conflict('published', 'veiling', true), 'veiling mag gepubliceerd');
must(!lot_publication_conflict('published', 'markt', false), 'markt zonder beperking');
echo "Kavelrechten: alle controles geslaagd\n";
