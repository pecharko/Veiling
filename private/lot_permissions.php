<?php
declare(strict_types=1);

/**
 * Centrale rechtenregels voor kavelmutaties. De caller moet de opgeslagen
 * status en maker onder database-lock ophalen; vertrouw nooit op POST-velden.
 * Dit bestand heeft bewust geen database- of sessie-bijwerkingen.
 */
function lot_can_create(string $role): bool {
  return in_array($role, ['assistant', 'veilingmeester', 'administrator'], true);
}

function lot_can_edit(string $role, int $actorId, array $lot): bool {
  if (in_array($role, ['administrator', 'veilingmeester'], true)) return true;
  if ($role !== 'assistant' || $actorId <= 0) return false;
  return ($lot['status'] ?? '') === 'draft'
    && isset($lot['created_by_admin_id'])
    && $lot['created_by_admin_id'] !== null
    && (int)$lot['created_by_admin_id'] === $actorId;
}

function lot_can_change_publication(string $role): bool {
  return in_array($role, ['administrator', 'veilingmeester'], true);
}

/** No partial writes on forbidden market publication. */
function lot_publication_conflict(string $status, string $saleLocation, bool $auctionOnly): bool {
  return $status === 'published' && $saleLocation === 'markt' && $auctionOnly;
}
