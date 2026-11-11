<?php
declare(strict_types=1);

/**
 * Normalize optional seller mobile numbers to international E.164 representation.
 * Belgian numbers must be mobile prefixes (045-049); international entries must
 * use a country prefix and meet E.164 structural length requirements.
 * This does not verify whether a subscriber number is actually assigned.
 */
function seller_mobile_validate(string $input): string {
  $mobile=trim($input);
  if ($mobile==='') return '';
  if (strlen($mobile)>30 || !preg_match('/^\\+?[0-9][0-9 .()\\-]*$/D',$mobile)) {
    throw new InvalidArgumentException('Vul een geldig mobiel nummer in, bijvoorbeeld 0470 12 34 56 of +32 470 12 34 56.');
  }
  $compact=preg_replace('/[ .()\\-]/','',$mobile);
  if (str_starts_with($compact,'00')) $compact='+'.substr($compact,2);
  if (preg_match('/^04[5-9][0-9]{7}$/D',$compact)) {
    $compact='+32'.substr($compact,1);
  }
  if (!preg_match('/^\\+[1-9][0-9]{7,14}$/D',$compact)) {
    throw new InvalidArgumentException('Gebruik voor mobiele nummers een geldig Belgisch gsm-nummer of een internationaal nummer met landcode.');
  }
  if (str_starts_with($compact,'+32') && !preg_match('/^\\+324[5-9][0-9]{7}$/D',$compact)) {
    throw new InvalidArgumentException('Vul een geldig Belgisch gsm-nummer in (04xx xx xx xx of +32 4xx xx xx xx).');
  }
  return $compact;
}
