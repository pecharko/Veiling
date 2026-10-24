<?php
declare(strict_types=1);

/**
 * Toon na een bewaarfout de ingestuurde veldwaarden opnieuw.
 * Alleen de bekende formuliervelden worden overgenomen. Escaping gebeurt
 * pas in de HTML-template met e(), net als voor databasegegevens.
 */
function lot_form_values(array $defaults, ?array $lot, ?array $submitted = null): array {
  $values = array_merge($defaults, $lot ?? []);
  if ($submitted !== null) {
    foreach (array_keys($defaults) as $field) {
      $input = $submitted[$field] ?? null;
      if (is_string($input) || is_int($input) || is_float($input)) {
        $values[$field] = (string)$input;
      }
    }
  }
  $values['status'] = ($values['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
  return $values;
}

/** Find the smallest unused positive lot number (1..999999). */
function first_available_lot_number(iterable $existingNumbers): ?int {
  $candidate = 1;
  foreach ($existingNumbers as $number) {
    $number = (int)$number;
    if ($number < $candidate) continue;
    if ($number > $candidate) break;
    ++$candidate;
    if ($candidate > 999999) return null;
  }
  return $candidate;
}
