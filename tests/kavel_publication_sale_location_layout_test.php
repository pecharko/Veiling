<?php
declare(strict_types=1);

/** Publication section is visible only to administrators and auction masters. */
$source = file_get_contents(dirname(__DIR__).'/catalogus-public/admin/kavel.php');
if (!is_string($source)) throw new RuntimeException('Missing lot editor');
function publicationCheck(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}
$guard = '<?php if (lot_can_change_publication(effective_admin_role())): ?>';
$panelTag = '<section class="lot-editor-card lot-editor-publication">';
publicationCheck(str_contains($source, $guard . "\n        " . $panelTag), 'Publication card must be role restricted');
publicationCheck(str_contains($source, '        </section>'."\n".'        <?php endif; ?>'."\n".'    <div class="lot-editor-layout lot-editor-layout--single">'), 'Publication guard leaves main form visible');
publicationCheck(str_contains($source, "\$status = lot_can_change_publication($role)"), 'Authorized roles may publish a new lot');
publicationCheck(str_contains($source, ": ($id ? (string)($lot['sale_location'] ?? 'markt') : 'markt');"), 'Assistant-created lots stay on Markt');
publicationCheck(str_contains($source, 'lot_can_change_publication($role) ? (string)'), 'Assistant POST cannot forge sale-location changes');
$pub = strpos($source, $panelTag);
$end = $pub === false ? false : strpos($source, '</section>', $pub);
$basic = strpos($source, '<h2>Basisinformatie</h2>');
publicationCheck($pub !== false && $end !== false && $basic !== false && $pub < $basic, 'Publication card precedes basic information');
$panel = substr($source, $pub, $end-$pub);
publicationCheck(str_contains($panel, '<div class="lot-editor-fields lot-editor-two">'), 'Publication retains responsive layout');
publicationCheck(substr_count($source, 'name="sale_location"') === 1 && str_contains($panel, 'name="sale_location"'), 'Sale location appears only once within publication section');
publicationCheck(str_contains($panel, '<label>Verkoopplaats <select name="sale_location">'), 'Admin and auction master may select sale location on new lots');
publicationCheck(strpos($panel, 'name="status"') < strpos($panel, 'name="sale_location"'), 'Status appears before sale location');
publicationCheck(str_contains($panel, 'class="lot-editor-help lot-full"'), 'Publication hint spans both columns');
publicationCheck(str_contains($source, "in_array(\$saleLocation,['markt','veiling'],true)"), 'Sale location is validated server-side');
echo "PASS: publication restricted by role, with editable controls and safe assistant defaults.\n";
