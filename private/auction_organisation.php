<?php
declare(strict_types=1);
require_once __DIR__.'/lot_audit.php';

/** Pure ordering logic, independent of the database. */
function auction_move_ids(array $ids, int $lotId, int $position): array {
  $ids = array_map('intval', $ids);
  if (count($ids) !== count(array_unique($ids)) || !in_array($lotId, $ids, true)) {
    throw new InvalidArgumentException('Ongeldige kavelkeuze.');
  }
  if ($position < 1 || $position > count($ids)) {
    throw new InvalidArgumentException('Veilingsnummer buiten bereik.');
  }
  $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $lotId));
  array_splice($ids, $position - 1, 0, [$lotId]);
  return $ids;
}

/**
 * Caller holds the catalog-wide advisory lock and a DB transaction.
 * Clearing to NULL first avoids temporary collisions with the UNIQUE index.
 * A failure rolls back both phases, preserving original numbers.
 */
function auction_apply_order(PDO $pdo, array $ids, int $actorId): void {
  if (!$pdo->inTransaction()) throw new LogicException('Transactie vereist.');
  if ($actorId<1) throw new InvalidArgumentException('Ingelogde bewerker vereist voor wijziging van veilingsnummers.');
  if (count($ids) !== count(array_unique($ids)) || count($ids) > 999999) {
    throw new InvalidArgumentException('Ongeldige veilingvolgorde.');
  }
  $previous=$pdo->query("SELECT id,auction_number FROM lots WHERE sale_location='veiling'")->fetchAll(PDO::FETCH_KEY_PAIR);
  $pdo->exec("UPDATE lots SET auction_number=NULL WHERE sale_location='veiling'");
  $update = $pdo->prepare("UPDATE lots SET auction_number=? WHERE id=? AND sale_location='veiling'");
  foreach ($ids as $i => $id) {
    $update->execute([$i + 1, $id]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Veiling is tussentijds gewijzigd.');
    // Log the effective before/after change, including every shifted neighbor.
    if ((int)($previous[$id]??0)!==$i+1) lot_audit_touch($pdo,(int)$id,$actorId);
  }
}
