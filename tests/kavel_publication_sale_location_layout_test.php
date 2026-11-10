<?php
declare(strict_types=1);

/** Publication fields must have status left and sale location right, without altering POST handling. */
$source = file_get_contents(dirname(__DIR__).'/catalogus-public/admin/kavel.php');
if (!is_string($source)) throw new RuntimeException('Missing kavel editor');
// Only existing lots render the publication panel; new lots cannot bypass defaults.
if (!str_contains($source, '<?php if ($lot): ?>'."\n".'        <section class="lot-editor-card lot-editor-publication">')) {
    throw new RuntimeException('Publication card must be hidden when creating a new lot');
}
if (!str_contains($source, '        </section>'."\n".'        <?php endif; ?>'."\n".'    <div class="lot-editor-layout lot-editor-layout--single">')) {
    throw new RuntimeException('Publication guard must not hide the basic information form');
}
if (!str_contains($source, "\$status = !\$id ? 'draft'") || !str_contains($source, "\$saleLocation=\$id ? (string)(\$_POST['sale_location']??'markt') : 'markt';")) {
    throw new RuntimeException('New lots must be saved as draft and on Markt, even if POST fields are forged');
}
$pub = strpos($source, '<section class="lot-editor-card lot-editor-publication">');
$end = $pub === false ? false : strpos($source, '</section>', $pub);
$basic = strpos($source, '<h2>Basisinformatie</h2>');
if ($pub === false || $end === false || $basic === false) throw new RuntimeException('Missing editor sections');
$panel = substr($source, $pub, $end - $pub);
if (!str_contains($panel, '<div class="lot-editor-fields lot-editor-two">')) throw new RuntimeException('Publication must use two-column responsive layout');
if (substr_count($source, 'name="sale_location"') !== 1 || !str_contains($panel, 'name="sale_location"')) throw new RuntimeException('Sale location must appear once inside publication');
$status = strpos($panel, 'name="status"');
$sale = strpos($panel, 'name="sale_location"');
if ($status === false || $sale === false || $sale <= $status) throw new RuntimeException('Sale location must follow status on the right');
if (!str_contains($panel, "lot_can_change_publication(effective_admin_role()) && \$canEditLot")) throw new RuntimeException('Status permission guard must remain');
if (!str_contains($panel, 'class="lot-editor-help lot-full"')) throw new RuntimeException('Publication hint must span both columns');
if (str_contains(substr($source, $basic), 'name="sale_location"')) throw new RuntimeException('Sale location must not remain in basic information');
if (!str_contains($source, "\$saleLocation=(string)(\$_POST['sale_location']??'markt');") || !str_contains($source, "in_array(\$saleLocation,['markt','veiling'],true)")) throw new RuntimeException('Sale-location validation must be retained');
echo "PASS: sale location is alongside publication status; behavior unchanged.\n";
