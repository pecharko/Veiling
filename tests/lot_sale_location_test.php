<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/lot_form.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$defaults=['lot_number'=>'1','sale_location'=>'markt','status'=>'draft'];
check(lot_form_values($defaults,null)['sale_location']==='markt','new lots default to markt');
check(lot_form_values($defaults,null,['sale_location'=>'veiling'])['sale_location']==='veiling','veiling accepted');
check(lot_form_values($defaults,null,['sale_location'=>'other'])['sale_location']==='markt','unknown location normalized');
check(lot_form_values($defaults,['sale_location'=>'veiling'])['sale_location']==='veiling','existing auction location restored');
check(lot_form_values($defaults,null,['sale_location'=>'markt'])['sale_location']==='markt','market accepted');
$editor=file_get_contents(dirname(__DIR__).'/catalogus-public/admin/kavel.php');
check(str_contains($editor,'name="sale_location"'),'sale location editor field present');
check(str_contains($editor,'next_auction_number($pdo)'),'auction number assigned');
check(str_contains($editor,'str_contains($detail,\'uq_lots_auction_number\')'),'auction duplicate is diagnosed separately');
check(str_contains($editor,'$error=\'Dit lotnummer bestaat al. Kies een uniek nummer.\''),'lot duplicate retains original error and suggestion');
check(str_contains($editor,'$lot[\'auction_number\'] ?? null'),'existing number preserved');
check(str_contains($editor, 'if ($lot[\'auction_number\'] !== null)'),'numbered lots cannot be deleted');
check(str_contains($editor, 'Dit kavel heeft een definitief veilingsnummer'),'deletion guard provides user feedback');
$migration=file_get_contents(dirname(__DIR__).'/database/lot_sale_location_migration.sql');
check(str_contains($migration,'UNIQUE KEY uq_lots_auction_number'),'unique index present');
check(str_contains($migration,"DEFAULT 'markt'"),'db default market');
echo "PASS sale location and auction number contracts\n";
