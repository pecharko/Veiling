<?php
/** Isolated MySQL 8.4 experiment: NEVER connect to OVH or production. */
declare(strict_types=1);
$dsn = getenv('CLAIM_TEST_DSN') ?: '';
$user = getenv('CLAIM_TEST_USER') ?: '';
$pass = getenv('CLAIM_TEST_PASSWORD') ?: '';
if ($dsn !== 'mysql:host=127.0.0.1;port=3306;dbname=claim_ci;charset=utf8mb4' || $user !== 'ci') {
    fwrite(STDERR, "Local CI datasource only\n"); exit(2);
}
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = file_get_contents(__DIR__ . '/../database/controller_release_claims_migration.sql');
if ($sql === false) { throw new RuntimeException('Migration missing'); }
$pdo->exec($sql);
$indexes = $pdo->query("SHOW INDEX FROM controller_release_claims")->fetchAll(PDO::FETCH_ASSOC);
$unique = [];
foreach ($indexes as $row) { if ((int)$row['Non_unique'] === 0) $unique[(string)$row['Key_name']] = true; }
foreach (['uq_claim_comment', 'uq_claim_release_sha', 'uq_claim_release_id'] as $name) {
    if (!isset($unique[$name])) throw new RuntimeException("Missing unique index $name");
}
$insert = $pdo->prepare("INSERT INTO controller_release_claims(repository,issue_id,comment_id,release_id,release_sha) VALUES ('pecharko/Veiling',?,?,?,?)");
function addClaim(PDOStatement $insert, int $issue, int $comment, string $release, string $sha): void {
    $insert->execute([$issue,$comment,$release,$sha]);
}
addClaim($insert,11,12,'release-demo',str_repeat('a',40));
foreach ([[11,12,'release-other',str_repeat('b',40)],
          [11,13,'release-demo',str_repeat('a',40)],
          [22,33,'release-demo',str_repeat('b',40)]] as $row) {
    try { addClaim($insert,...$row); throw new RuntimeException('Duplicate was accepted'); }
    catch (PDOException $e) { if ($e->getCode() !== '23000') throw $e; }
}
$count = (int)$pdo->query('SELECT COUNT(*) FROM controller_release_claims')->fetchColumn();
if ($count !== 1) throw new RuntimeException("Expected one claim, got $count");
$pdo->beginTransaction();
addClaim($insert,22,33,'release-second',str_repeat('c',40));
$pdo->rollBack();
$count = (int)$pdo->query('SELECT COUNT(*) FROM controller_release_claims')->fetchColumn();
if ($count !== 1) throw new RuntimeException('Rollback failure');
echo "MySQL 8.4 isolated migration, uniqueness and rollback OK\n";
