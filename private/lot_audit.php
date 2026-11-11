<?php
declare(strict_types=1);

/**
 * Record a genuine lot mutation after it has succeeded.
 * Call under catalog mutex (inside current write transaction when available).
 * Historic creator data is not overwritten; no foreign key to deleted accounts.
 */
function lot_audit_touch(PDO $pdo,int $lotId,int $actorId): void {
  if ($lotId<1 || $actorId<1) throw new InvalidArgumentException('Ongeldige kavel- of gebruikersidentificatie.');
  $st=$pdo->prepare('UPDATE lots SET last_edited_by_admin_id=?, last_edited_at=UTC_TIMESTAMP() WHERE id=?');
  $st->execute([$actorId,$lotId]);
  if ($st->rowCount()!==1) throw new RuntimeException('Kavel niet gevonden voor auditregistratie.');
}
/** UTC audit values are displayed in the visitor's local administrative timezone. */
function lot_audit_label(?string $timestamp): string {
  if ($timestamp===null || $timestamp==='') return 'Niet geregistreerd';
  return (new DateTimeImmutable($timestamp,new DateTimeZone('UTC')))
    ->setTimezone(new DateTimeZone('Europe/Brussels'))->format('d/m/Y H:i');
}
