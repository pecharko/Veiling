<?php
declare(strict_types=1);
require_once __DIR__.'/lot_audit.php';

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
  $values['sale_location'] = ($values['sale_location'] ?? 'markt') === 'veiling' ? 'veiling' : 'markt';
  $values['status'] = in_array(($values['status'] ?? 'draft'), ['draft', 'published', 'sold', 'canceled'], true) ? $values['status'] : 'draft';
  return $values;
}

/**
 * Show saved DECIMAL euro amounts with exactly two digits after the separator.
 * type=number requires a dot in its HTML value, even in Dutch locales.
 * On validation errors preserve exactly what was submitted for correction.
 */
function lot_price_input_value(mixed $value, bool $submitted = false): string {
  if ($value === null || $value === '') return '';
  if ($submitted || !is_numeric($value)) return (string)$value;
  return number_format((float)$value, 2, '.', '');
}

/** Suggest the next lot number after the highest existing one (1..999999). */
function first_available_lot_number(iterable $existingNumbers): ?int {
  $maximum = 0;
  foreach ($existingNumbers as $number) {
    $maximum = max($maximum, (int)$number);
  }
  return $maximum >= 999999 ? null : $maximum + 1;
}

/**
 * Assign the next auction number while the catalog advisory lock is held.
 * Auction numbers belong only to auction lots. A market transfer clears the number;
 * compact_auction_numbers() then closes gaps under the same catalog mutex.
 * The DB unique index prevents accidental duplicates.
 */
function next_auction_number(PDO $pdo): int {
  $highest = $pdo->query('SELECT MAX(auction_number) FROM lots')->fetchColumn();
  $next = max(0, (int)$highest) + 1;
  if ($next > 999999) throw new RuntimeException('Geen veilingsnummers meer beschikbaar.');
  return $next;
}

/**
 * Compact all active auction numbers to 1..N after removing an auction lot.
 * Caller must hold catalog_lock_for_request() and a DB transaction.
 * Updating in ascending order frees each next target under the UNIQUE index.
 */
function compact_auction_numbers(PDO $pdo, ?int $actorId=null): void {
  if (!$pdo->inTransaction()) throw new LogicException('Transaction required for auction renumbering.');
  $rows = $pdo->query("SELECT id, auction_number FROM lots WHERE sale_location='veiling' ORDER BY auction_number, id")->fetchAll(PDO::FETCH_ASSOC);
  $update = $pdo->prepare('UPDATE lots SET auction_number=? WHERE id=?');
  foreach ($rows as $index => $row) {
    $expected = $index + 1;
    if ((int)$row['auction_number'] !== $expected) {
      $update->execute([$expected, (int)$row['id']]);
      if ($actorId!==null) lot_audit_touch($pdo,(int)$row['id'],$actorId);
    }
  }
}

/**
 * Compare the effective values of a lot to its persisted row. Normalize MySQL
 * DECIMAL(,2), nullable foreign keys and integer flags to avoid marking a
 * visually unchanged form as a modification.
 */
function lot_effective_fields_changed(array $stored, array $values): bool {
  $keys=['lot_number','title','subtitle','category','description','details',
    'market_price','starting_bid','auction_only','status','sale_location',
    'auction_number','seller_id'];
  if (count($values)!==count($keys)) throw new InvalidArgumentException('Ongeldige kavelvergelijking.');
  foreach ($keys as $i=>$key) {
    $old=$stored[$key]??null;
    $next=$values[$i];
    if (in_array($key,['market_price','starting_bid'],true)) {
      $old=($old===null||$old==='')?null:number_format((float)$old,2,'.','');
      $next=($next===null||$next==='')?null:number_format((float)$next,2,'.','');
    } elseif (in_array($key,['lot_number','auction_only','auction_number','seller_id'],true)) {
      $old=($old===null||$old==='')?null:(int)$old;
      $next=($next===null||$next==='')?null:(int)$next;
    } else {
      $old=(string)($old??'');
      $next=(string)($next??'');
    }
    if ($old!==$next) return true;
  }
  return false;
}
