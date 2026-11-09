<?php
declare(strict_types=1);
// Offline, non-mutating public catalog and editor title contract.
$root = dirname(__DIR__);
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
$catalog = file_get_contents($root . '/catalogus-public/index.php');
$editor = file_get_contents($root . '/catalogus-public/admin/kavel.php');
if ($css === false || $catalog === false || $editor === false) {
    throw new RuntimeException('Missing title layout source files');
}
function verify_title(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
}
verify_title((bool)preg_match('~\\.lot-card h3\\{[^}]*min-height:0;[^}]*white-space:nowrap[^}]*\\}~', $css),
    'Catalog title does not reserve extra lines on desktop or mobile');
verify_title((bool)preg_match('~\\.lot-card h3 a\\{[^}]*display:block;[^}]*overflow:hidden;[^}]*text-overflow:ellipsis;[^}]*white-space:nowrap[^}]*\\}~', $css),
    'Catalog card title link truncates visually with ellipsis');
verify_title(str_contains($catalog, 'title="<?=e($lot[\'title\'])?>"'),
    'Full existing title is still accessible on hover');
verify_title(str_contains($catalog, '><?=e($lot[\'title\'])?></a></h3>'),
    'Full existing title is retained in the HTML and database');
verify_title(str_contains($editor, 'name="title"') && str_contains($editor, 'maxlength="50"'),
    'Title input has a fifty character browser limit');
verify_title(str_contains($editor, 'mb_strlen($title)>50') && str_contains($editor, 'maximaal 50 tekens'),
    'Server validates the limit before writing');
verify_title(!str_contains($editor, '$title=mb_substr(trim((string)($_POST[\'title\']??\'\')),0,50)'),
    'Existing titles are not silently truncated');
echo "PASS: catalog titles single-line with ellipsis; input and server cap at 50; full stored titles preserved.\\n";
