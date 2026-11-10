<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$editor=file_get_contents($root.'/catalogus-public/admin/kavel.php');
$page=file_get_contents($root.'/catalogus-public/admin/categorieen.php');
$roles=file_get_contents($root.'/private/bootstrap.php');
$logic=file_get_contents($root.'/private/lot_categories.php');
$sql=file_get_contents($root.'/database/migrations/20261110_lot_categories.sql');
if (!is_string($editor)||!is_string($page)||!is_string($roles)||!is_string($logic)||!is_string($sql)) throw new RuntimeException('Category files missing');
function categoryCheck(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
categoryCheck(str_contains($editor,'<select name="category">') && !str_contains($editor,'<input type="text" name="category"'), 'Category dropdown replaces free text');
categoryCheck(str_contains($editor,'lot_editor_category_nonexistent')===false, 'No unexpected category escape');
categoryCheck(str_contains($editor,"lot_category_exists(\$pdo,\$category)"), 'No arbitrary POST category accepted');
categoryCheck(str_contains($editor,"'category'=>''"), 'New lot defaults to no category');
categoryCheck(str_contains($editor,'lot_category_can_manage(effective_admin_role())'), 'Edit action role-gated');
categoryCheck(str_contains($page,'check_csrf()') && str_contains($page,'catalog_lock_for_request($pdo)'), 'Category mutations require CSRF and shared lock');
categoryCheck(str_contains($page,'if (!lot_category_can_manage(effective_admin_role()))'), 'Backend permission required');
categoryCheck(str_contains($roles,"if (\$role === 'veilingmeester') \$allowed[] = 'categorieen.php';"), 'Only veilingmeester and administrator can navigate to management');
categoryCheck(str_contains($logic,"'administrator', 'veilingmeester'"), 'Role policy source');
categoryCheck(str_contains($logic,'SELECT COUNT(*) FROM lots WHERE category=?'), 'Delete blocks used categories');
categoryCheck(str_contains($logic,'UPDATE lots SET category=? WHERE category=?'), 'Rename propagates to linked lots');
categoryCheck(str_contains($sql,"('Moderne Kunst'), ('Oude Meesters')") && str_contains($sql,"UPDATE lots SET category='' WHERE category<>'';"), 'Migration seeds and clears legacy categories');
require $root.'/private/lot_categories.php';
foreach (['Moderne Kunst','Oude Meesters'] as $name) categoryCheck(lot_category_clean_name($name)===$name,'valid category');
foreach (['',str_repeat('a',121),['x']] as $bad) {
  try {lot_category_clean_name($bad);throw new RuntimeException('Invalid category accepted');}
  catch(InvalidArgumentException $ex){}
}
echo "PASS category CRUD scope, names, permissions, dropdown and migration contract\n";
