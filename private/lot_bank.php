<?php
declare(strict_types=1);

/** Private per-lot bank details; never render them in the public catalog. */
function lot_bank_validate(mixed $holder, mixed $number): array {
  if (!is_string($holder) || !is_string($number)) {
    throw new InvalidArgumentException('Ongeldige bankgegevens.');
  }
  $holder = trim($holder);
  if (mb_strlen($holder, 'UTF-8') > 100) {
    throw new InvalidArgumentException('Naam bankrekeningnummer mag maximaal 100 tekens bevatten.');
  }
  // Accept separators in the form, store a canonical uppercase IBAN.
  $iban = strtoupper(preg_replace('/[\x20\t]/', '', trim($number)));
  if ($iban === '') return [$holder, ''];
  if (strlen($iban) < 15 || strlen($iban) > 34 ||
      !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/D', $iban)) {
    throw new InvalidArgumentException('Vul een geldig IBAN-bankrekeningnummer in.');
  }
  // ISO 13616: rearrange the first 4 characters, convert letters A=10..Z=35,
  // then calculate mod 97 incrementally to avoid integer overflow.
  $rearranged = substr($iban, 4) . substr($iban, 0, 4);
  $remainder = 0;
  for ($i = 0, $length = strlen($rearranged); $i < $length; $i++) {
    $char = $rearranged[$i];
    $digits = ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
    for ($j = 0, $n = strlen($digits); $j < $n; $j++) {
      $remainder = ($remainder * 10 + (int)$digits[$j]) % 97;
    }
  }
  if ($remainder !== 1) {
    throw new InvalidArgumentException('Het IBAN-controlegetal is ongeldig.');
  }
  return [$holder, $iban];
}
