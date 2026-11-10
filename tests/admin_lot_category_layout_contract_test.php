<?php
declare(strict_types=1);
// Static layout contract; no database or production dependency.
$root=dirname(__DIR__);
$editor=file_get_contents($root.'/catalogus-public/admin/kavel.php');
$manager=file_get_contents($root.'/catalogus-public/admin/categorieen.php');
$css=file_get_contents($root.'/catalogus-public/admin/admin.css');
if (!is_string($editor)||!is_string($manager)||!is_string($css)) throw new RuntimeException('Editor layout files missing.');
function checkCategoryLayout(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException($msg);}
$base=strpos($editor,'<h2>Basisinformatie</h2>');
$details=$base===false?false:strpos($editor,'</section>',$base);
checkCategoryLayout($base!==false&&$details!==false,'Basic information section exists');
$block=substr($editor,$base,$details-$base);
$flag=strpos($block,'name="auction_only"');
$lot=strpos($block,'name="lot_number"');
$category=strpos($block,'name="category"');
checkCategoryLayout($flag!==false&&$lot!==false&&$category!==false&&$flag<$lot&&$lot<$category,'Auction-only checkbox must precede lot number on both forms');
checkCategoryLayout(substr_count($editor,'name="auction_only"')===1,'Auction-only checkbox appears exactly once in shared form');
checkCategoryLayout(str_contains($block,'lot-editor-auction-flag lot-full')||str_contains($block,'lot-full lot-editor-auction-flag'),'Flag takes the full row');
checkCategoryLayout(str_contains($css,'.lot-editor-checkbox.lot-editor-auction-flag')&&str_contains($css,'grid-column:1/-1'),'Checkbox remains above lot number on desktop and mobile');
checkCategoryLayout(str_contains($manager,'category-manager-grid')&&str_contains($manager,'lot-editor-card category-manager-card'),'Manager reuses admin editor panels');
checkCategoryLayout(substr_count($manager,'lot-editor-card category-manager-card')===2,'Separate add and manage cards');
checkCategoryLayout(str_contains($manager,'category-manager-usage')&&str_contains($manager,'category-manager-delete-button'),'Usage and delete controls remain visible');
checkCategoryLayout(str_contains($manager,'check_csrf()')&&str_contains($manager,'catalog_lock_for_request($pdo)'),'Category authorization and mutation guards preserved');
checkCategoryLayout(str_contains($manager,'if (!lot_category_can_manage(effective_admin_role()))'),'Category manager role boundary preserved');
checkCategoryLayout(str_contains($css,'@media(max-width:950px){.category-manager-grid'),'Category manager responsive');
echo "PASS shared auction-only field order and category management layout\n";
