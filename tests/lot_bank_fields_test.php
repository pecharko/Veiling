<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/lot_bank.php';
function bank_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}
function bank_reject(mixed $name, mixed $iban, string $message): void {
  try {
    lot_bank_validate($name, $iban);
  } catch (InvalidArgumentException) {
    return;
  }
  throw new RuntimeException($message);
}
bank_check(lot_bank_validate('', '') === ['', ''], 'Optional empty bank fields');
bank_check(lot_bank_validate('  Rekeninghouder  ', 'be68 5390 0754 7034') ===
  ['Rekeninghouder', 'BE68539007547034'], 'Belgian IBAN canonicalized');
bank_check(lot_bank_validate('Client', 'GB82 WEST 1234 5698 7654 32') ===
  ['Client', 'GB82WEST12345698765432'], 'GB IBAN checksum accepted');
bank_reject('Client', 'BE67539007547034', 'Incorrect checksum rejected');
bank_reject('Client', 'BE68539007547035', 'Modified IBAN rejected');
bank_reject('Client', 'BE68', 'Short IBAN rejected');
bank_reject('Client', 'BE68!39007547034', 'Illegal character rejected');
bank_reject('Client', 'BE685390075470341', 'Incorrect country length rejected');
bank_reject([], '', 'Array holder rejected');
bank_reject('Client', [], 'Array IBAN rejected');
bank_reject(str_repeat('A', 101), '', 'Overlength holder rejected');
bank_check(mb_strlen(lot_bank_validate(str_repeat('A', 100), '')[0]) === 100,
  '100-character holder accepted');
$root = dirname(__DIR__);
$editor = file_get_contents($root . '/catalogus-public/admin/kavel.php');
$sellerEditor = file_get_contents($root . '/catalogus-public/admin/verkoper.php');
$sellerValidation = file_get_contents($root . '/private/lot_sellers.php');
$migration = file_get_contents($root . '/database/migrations/20261110_lot_bank_fields.sql');
$public = file_get_contents($root . '/catalogus-public/index.php');
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
bank_check(is_string($editor) && is_string($migration) &&
  is_string($public) && is_string($detail), 'Expected files present');
bank_check(str_contains($editor,'if (lot_can_change_publication(effective_admin_role()))'),
  'Publication box must be role gated');
bank_check(str_contains($editor,'lot_can_change_publication($role)'),
  'Publication and sale-location POST authorization must be server-side');
bank_check(!str_contains($editor,'name="bank_account_name"') && !str_contains($editor,'name="bank_account_iban"'),
  'Lot editor delegates seller bank details to central registry');
bank_check(str_contains($sellerEditor,'name="bank_account_name"')
  && str_contains($sellerEditor,'maxlength="100"')
  && str_contains($sellerEditor,'name="bank_account_iban"'),
  'Seller editor contains optional bank holder and IBAN fields');
bank_check(str_contains($sellerValidation,'lot_bank_validate('),
  'Seller save validates bank details server-side');
bank_check(str_contains($migration,'bank_account_name VARCHAR(100)') &&
  str_contains($migration,'bank_account_iban VARCHAR(34)'),
  'Migration uses bounded private text columns');
bank_check(!str_contains($public,'bank_account_') &&
  !str_contains($detail,'bank_account_'),
  'Public catalog does not expose private bank fields');
echo "Bank IBAN validation and editor guards passed.\n";
