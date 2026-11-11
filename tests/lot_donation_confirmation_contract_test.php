<?php
declare(strict_types=1);
// Donation consent must protect both creating and editing lots, regardless of JS.
$editor=file_get_contents(dirname(__DIR__).'/catalogus-public/admin/kavel.php');
$integration=file_get_contents(dirname(__DIR__).'/tests/kavel_http_integration.php');
if (!is_string($editor)||!is_string($integration)) throw new RuntimeException('Missing lot confirmation sources');
foreach ([
  'name="donation_confirmed" value="0"',
  "if (\$rawSeller==='' && (\$_POST['donation_confirmed']??'')!=='1'",
  'Er is geen verkoper geselecteerd',
  'geen uitbetaling gebeuren naar de schenker',
  'ga jij hiermee akkoord?',
  "lotForm.addEventListener('submit'",
  'event.preventDefault()',
  'sellerSelect.focus()',
  "donationConfirmed.value='1'",
  "sellerSelect.addEventListener('change'",
] as $required) {
  if (!str_contains($editor,$required)) throw new RuntimeException('Donation warning missing: '.$required);
}
if (!str_contains($integration,"'donation_confirmed'=>'0'")
    || !str_contains($integration,'Server rejects unconfirmed donation with no seller'))
  throw new RuntimeException('No-seller server rejection must be tested');
echo "PASS confirmation for lots without seller, client cancel and server refusal.\n";
