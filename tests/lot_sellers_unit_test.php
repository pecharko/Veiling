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
checkSeller($normal['mobile_number']==='', 'Mobile is optional for artists');
$base=[
  'seller_type'=>'particulier','name'=>'Demo particulier','email'=>'persoon@example.invalid',
  'street'=>'Teststraat','house_number'=>'2','postal_code'=>'9160','municipality'=>'Lokeren',
  'bank_account_name'=>'Demo particulier','bank_account_iban'=>'BE68 5390 0754 7034',
];
$mobileSeller=seller_clean(array_merge($base,['mobile_number'=>'0470 12 34 56']));
checkSeller($mobileSeller['mobile_number']==='+32470123456', 'Mobile number normalized on seller save');
$internationalSeller=seller_clean(array_merge($base,['mobile_number'=>'+44 7700 900123']));
checkSeller($internationalSeller['mobile_number']==='+447700900123', 'International mobile number accepted');
$private=seller_clean($base+['artist_name'=>'Should not be retained','artist_url'=>'https://example.org']);
checkSeller($private['artist_name']==='' && $private['artist_url']==='','Private seller cannot retain artist fields');
$withoutBank=seller_clean(array_merge($base,['bank_account_name'=>'','bank_account_iban'=>'']));
checkSeller($withoutBank['bank_account_name']==='' && $withoutBank['bank_account_iban']==='',
  'Seller can be created without any bank details');
$onlyIban=seller_clean(array_merge($base,['bank_account_name'=>'','bank_account_iban'=>'BE68 5390 0754 7034']));
checkSeller($onlyIban['bank_account_iban']==='BE68539007547034',
  'Optional supplied IBAN remains validated and normalized');

foreach ([
  ['seller_type'=>'unknown','name'=>'Demo'],
  array_merge($base,['email'=>'not-an-email']),
  array_merge($base,['mobile_number'=>'02 123 45 67']),
  array_merge($base,['bank_account_iban'=>'BE68 5390 0754 7035']),
  array_merge($base,['street'=>'']),
  array_merge($base,['house_number'=>'']),
  array_merge($base,['postal_code'=>'']),
  array_merge($base,['municipality'=>'']),
  array_merge($base,['seller_type'=>'kunstenaar','artist_name'=>'Demo','artist_url'=>'']),
  array_merge($base,['seller_type'=>'kunstenaar','artist_name'=>'','artist_url'=>'https://example.org']),
  array_merge($base,['seller_type'=>'kunstenaar','artist_name'=>'Demo','artist_url'=>'javascript:alert(1)']),
] as $bad) {
  try { seller_clean($bad); throw new RuntimeException('Invalid seller accepted'); }
  catch (InvalidArgumentException $expected) {}
}
checkSeller(seller_can_manage('administrator'),'Admin may manage seller');
checkSeller(seller_can_manage('assistant') && seller_can_manage('veilingmeester'), 'Assistants and auctioneers may manage sellers');
checkSeller(!seller_can_manage('invalid'), 'Unknown role cannot manage sellers');
echo "PASS: private seller role, bank, artist and field validation.\n";
