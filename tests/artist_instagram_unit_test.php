<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/lot_artist.php';
function igcheck(string $expected,string $input): void {
  $actual=artist_instagram_validate($input);
  if ($actual!==$expected) throw new RuntimeException('Instagram normalization failed: '.$input);
}
igcheck('', '');
igcheck('https://www.instagram.com/test.name_1/', '@test.name_1');
igcheck('https://www.instagram.com/testname/', 'https://instagram.com/testname/');
igcheck('https://www.instagram.com/testname/', 'https://www.instagram.com/testname');
foreach (['@','@bad..name','@.bad','@bad.','javascript:alert(1)','http://instagram.com/testname/',
  'https://instagram.com.evil.test/testname/','https://evil.test/?next=instagram.com',
  'https://instagram.com/testname/?query=1','https://instagram.com/testname/#page',
  'https://user@instagram.com/testname/','https://instagram.com:443/testname/',
  'https://instagram.com/testname/reel/','https://instagram.com/%2f/'] as $bad) {
  try { artist_instagram_validate($bad); throw new RuntimeException('Unsafe Instagram accepted'); }
  catch (InvalidArgumentException $ok) {}
}
echo "PASS strict Instagram profile normalization and rejection\n";
