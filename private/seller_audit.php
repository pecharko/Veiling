<?php
declare(strict_types=1);

/** Resolve the authenticated actor against DB data, never from POST input. */
function seller_actor_name(PDO $pdo,int $actorId): string {
  if ($actorId<=0) throw new InvalidArgumentException('Ongeldige ingelogde beheerder.');
  $st=$pdo->prepare('SELECT name,email FROM admins WHERE id=?');
  $st->execute([$actorId]);
  $actor=$st->fetch(PDO::FETCH_ASSOC);
  if (!$actor) throw new InvalidArgumentException('Ingelogde beheerder niet gevonden.');
  $name=trim((string)($actor['name']??''));
  return $name!=='' ? $name : (string)$actor['email'];
}
