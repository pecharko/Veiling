<?php
declare(strict_types=1);
// Read-only regression for the private admin lots overview.
$source=file_get_contents(dirname(__DIR__).'/catalogus-public/admin/index.php');
if (!is_string($source)) throw new RuntimeException('Admin lots overview missing.');
foreach ([
  'LEFT JOIN lot_sellers seller ON seller.id=l.seller_id',
  'seller.name AS seller_name',
  '<th scope="col">Verkoper</th>',
  'class="dashboard-seller"',
  "e((string)\$row['seller_name'])",
  '<td colspan="10">Geen kavels gevonden.',
] as $required) {
  if (!str_contains($source,$required)) throw new RuntimeException('Missing seller column: '.$required);
}
// Keep the entire address/contact/bank profile out of the general lots overview.
$presenceExpression = "COALESCE(TRIM(seller.bank_account_name)<>\'\' AND TRIM(seller.bank_account_iban)<>\'\',0) AS seller_bank_details_present";
if (substr_count($source,$presenceExpression)!==1 || !str_contains($source,'<th scope="col">Bank Nr.</th>')) {
  throw new RuntimeException('Bank Nr. must use only the bank-details presence flag.');
}
$source=str_replace($presenceExpression,'',$source);
foreach (['seller.email','seller.street','seller.bank_account_iban','seller.bank_account_name'] as $private) {
  if (str_contains($source,$private)) throw new RuntimeException('Private seller field exposed: '.$private);
}
if (!str_contains($source,"'—'")) throw new RuntimeException('Missing empty seller fallback.');
echo "PASS: seller name shown safely in admin lots table, with empty fallback.\n";
