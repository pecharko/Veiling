<?php
declare(strict_types=1);

/** These methods are used after the separate, reviewed lot_categories migration. */
function lot_category_can_manage(string $role): bool {
  return in_array($role, ['administrator', 'veilingmeester'], true);
}

function lot_category_names(PDO $pdo): array {
  return $pdo->query('SELECT name FROM lot_categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}


/**
 * Count lots using each category without comparing two columns with
 * potentially different MySQL utf8mb4 collations. The parameter comparison
 * inherits lots.category's collation; no schema edits or data writes needed.
 */
function lot_category_list_with_usage(PDO $pdo): array {
  $categories = $pdo->query('SELECT id,name FROM lot_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
  $count = $pdo->prepare('SELECT COUNT(*) FROM lots WHERE category=?');
  foreach ($categories as $index => $category) {
    $count->execute([(string)$category['name']]);
    $categories[$index]['lot_count'] = (int)$count->fetchColumn();
    $count->closeCursor();
  }
  return $categories;
}

function lot_category_exists(PDO $pdo, string $name): bool {
  $st=$pdo->prepare('SELECT 1 FROM lot_categories WHERE name=? LIMIT 1');
  $st->execute([$name]);
  return $st->fetchColumn() !== false;
}

function lot_category_clean_name(mixed $value): string {
  if (!is_string($value)) throw new InvalidArgumentException('Ongeldige categorienaam.');
  $name=trim($value);
  if ($name==='' || mb_strlen($name)>120) throw new InvalidArgumentException('Vul een categorienaam van maximaal 120 tekens in.');
  if (preg_match('/[\x00-\x1F\x7F]/u', $name)) throw new InvalidArgumentException('De categorienaam bevat ongeldige tekens.');
  return $name;
}

/** Callers must hold catalog_lock_for_request before any category mutation. */
function lot_category_add(PDO $pdo, mixed $value): void {
  $name=lot_category_clean_name($value);
  if (lot_category_exists($pdo,$name)) throw new InvalidArgumentException('Deze categorie bestaat al.');
  $st=$pdo->prepare('INSERT INTO lot_categories (name) VALUES (?)');
  $st->execute([$name]);
}

function lot_category_rename(PDO $pdo, int $id, mixed $value): void {
  $name=lot_category_clean_name($value);
  $pdo->beginTransaction();
  try {
    $st=$pdo->prepare('SELECT name FROM lot_categories WHERE id=? FOR UPDATE');
    $st->execute([$id]);
    $old=$st->fetchColumn();
    if ($old===false) throw new InvalidArgumentException('Categorie niet gevonden.');
    if ($old!==$name) {
      if (lot_category_exists($pdo,$name)) throw new InvalidArgumentException('Deze categorie bestaat al.');
      $update=$pdo->prepare('UPDATE lot_categories SET name=? WHERE id=?');
      $update->execute([$name,$id]);
      $lots=$pdo->prepare('UPDATE lots SET category=? WHERE category=?');
      $lots->execute([$name,$old]);
    }
    $pdo->commit();
  } catch(Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function lot_category_delete(PDO $pdo, int $id): void {
  $pdo->beginTransaction();
  try {
    $st=$pdo->prepare('SELECT name FROM lot_categories WHERE id=? FOR UPDATE');
    $st->execute([$id]);
    $name=$st->fetchColumn();
    if ($name===false) throw new InvalidArgumentException('Categorie niet gevonden.');
    $used=$pdo->prepare('SELECT COUNT(*) FROM lots WHERE category=?');
    $used->execute([$name]);
    if ((int)$used->fetchColumn()!==0) throw new InvalidArgumentException('Verwijderen kan alleen als geen enkel kavel deze categorie gebruikt.');
    $st=$pdo->prepare('DELETE FROM lot_categories WHERE id=?');
    $st->execute([$id]);
    $pdo->commit();
  } catch(Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
