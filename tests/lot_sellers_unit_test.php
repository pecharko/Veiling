<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/lot_sellers.php';
function checkSeller(bool $valid,string $what):void { if(!$valid) throw new RuntimeException($what); }
$normal=seller_clean([
  'seller_type'=>'kunstenaar','name'=>' Fictieve kunstenaar ',
  'email'=>'voorbeeld@example.invalid','street'=>'Teststraat','house_number'=>'7',
  'postal_code'=>'9160','municipality'=>'Lokeren','bank_account_name'=>'Voorbeeld',
  'bank_account_iban'=>'BE68 5390 0754 7034',
  'artist_name'=>'Demo','artist_url'=>'www.example.org'
]);
checkSeller($normal['name']==='Fictieve kunstenaar','Seller name normalized');
checkSeller($normal['bank_account_iban']==='BE68539007547034','Seller IBAN checksum/normalization');
checkSeller($normal['artist_url']==='https://www.example.org','Artist URL normalized');
$private=seller_clean(['seller_type'=>'particulier','name'=>'Demo particulier','artist_name'=>'Should not be retained','artist_url'=>'https://example.org']);
checkSeller($private['artist_name']==='' && $private['artist_url']==='','Private seller cannot retain artist fields');
foreach ([
 ['seller_type'=>'unknown','name'=>'Demo'],
 ['seller_type'=>'kunstenaar','name'=>'Demo','artist_url'=>'javascript:alert(1)'],
 ['seller_type'=>'particulier','name'=>'Demo','email'=>'not-an-email'],
 ['seller_type'=>'particulier','name'=>'Demo','bank_account_iban'=>'BE68 5390 0754 7035'],
] as $bad) {
  try { seller_clean($bad); throw new RuntimeException('Invalid seller accepted'); }
  catch (InvalidArgumentException $expected) {}
}
checkSeller(seller_can_manage('administrator'),'Admin may manage seller');
checkSeller(!seller_can_manage('assistant') && !seller_can_manage('veilingmeester'),'Seller personal details are admin only');
echo "PASS: private seller role, bank, artist and field validation.\n";
