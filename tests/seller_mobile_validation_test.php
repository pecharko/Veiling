<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/seller_mobile.php';
$valid=[
  ''=>'',
  '0470 12 34 56'=>'+32470123456',
  '0470123456'=>'+32470123456',
  '+32 470 12 34 56'=>'+32470123456',
  '0032 470 12 34 56'=>'+32470123456',
  '+44 7700 900123'=>'+447700900123',
  '+33 6 12 34 56 78'=>'+33612345678',
];
foreach($valid as $input=>$expected) {
  if (seller_mobile_validate($input)!==$expected) throw new RuntimeException('Mobile normalization failed for '.$input);
}
foreach(['048123','0470 12 34 567','02 123 45 67','+32 2 123 45 67',
  '12345','+000123456789','+32 470 12 34 abc','0470<script>',str_repeat('1',31)] as $invalid) {
  try {
    seller_mobile_validate($invalid);
    throw new RuntimeException('Invalid number accepted: '.$invalid);
  } catch (InvalidArgumentException $expected) {}
}
echo "PASS optional mobile format, Belgian GSM prefixes, E.164 and invalid inputs.\n";
