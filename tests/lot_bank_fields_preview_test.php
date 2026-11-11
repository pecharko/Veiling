<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/lot_bank.php';
function checkBank(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}
checkBank(lot_bank_validate('', '') === ['', ''], 'Empty bank details are optional');
checkBank(lot_bank_validate('  Begunstigde  ', 'be68 5390 0754 7034') === ['Begunstigde', 'BE68539007547034'], 'Valid Belgian IBAN is canonicalized');
checkBank(lot_bank_validate('A', 'GB82 WEST 1234 5698 7654 32') === ['A', 'GB82WEST12345698765432'], 'Valid British IBAN is accepted');
foreach (['BE67539007547034', 'BE68539007547035', 'BE12', 'BE68!39007547034', '1234567890123456'] as $invalid) {
  try {
    lot_bank_validate('Valid', $invalid);
    throw new RuntimeException('Invalid IBAN unexpectedly accepted: ' . $invalid);
  } catch (InvalidArgumentException) {
    // Invalid checksums and invalid syntax must all fail.
  }
}
checkBank(mb_strlen(lot_bank_validate(str_repeat('A', 100), '')[0]) === 100, '100-character holder is accepted');
try {
  lot_bank_validate(str_repeat('A', 101), '');
  throw new RuntimeException('101-character holder unexpectedly accepted');
} catch (InvalidArgumentException) {}
try {
  lot_bank_validate([], '');
  throw new RuntimeException('Non-string holder unexpectedly accepted');
} catch (InvalidArgumentException) {}

$editor = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/kavel.php');
$sellerEditor = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/verkoper.php');
$sellerValidation = file_get_contents(dirname(__DIR__) . '/private/lot_sellers.php');
$preview = file_get_contents(dirname(__DIR__) . '/dev/preview/seed.php');
$schema = file_get_contents(dirname(__DIR__) . '/database/migrations/20261110_lot_bank_fields.sql');
checkBank(is_string($editor) && str_contains($editor, 'if (lot_can_change_publication(effective_admin_role()))'), 'Publication section is role-restricted');
checkBank(str_contains($editor, 'lot_can_change_publication($role) ? (string)($_POST'), 'Assistants cannot bypass publication role using POST');
checkBank(!str_contains($editor,'name="bank_account_iban"'), 'Lot editor no longer duplicates bank account data');
checkBank(str_contains($sellerEditor,'Naam bankrekeningnummer') && str_contains($sellerEditor,'name="bank_account_iban"'), 'Bank fields are in private seller editor');
checkBank(str_contains($sellerValidation, 'lot_bank_validate('), 'Bank account is server validated on seller save');
checkBank(str_contains($preview, "SHOW COLUMNS FROM lots LIKE 'bank_account_name'"), 'Isolated preview can migrate bank fields');
checkBank(is_string($schema) && str_contains($schema, 'VARCHAR(100)') && str_contains($schema, 'VARCHAR(34)'), 'Migration reserves correct column lengths');
echo "Bank fields and role restrictions: OK\n";
