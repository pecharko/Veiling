<?php
declare(strict_types=1);
/** Create only an isolated CI database; never load production credentials. */
$host = getenv('CI_DB_HOST') ?: '127.0.0.1';
$name = getenv('CI_DB_NAME') ?: 'auction_test';
$user = getenv('CI_DB_USER') ?: 'root';
$pass = getenv('CI_DB_PASSWORD') ?: '';
if ($name !== 'auction_test' || !($host === '127.0.0.1' ||
    ($host === 'preview-db' && getenv('LOCAL_PREVIEW_ISOLATED') === '1'))) {
  throw new RuntimeException('Isolated-test database settings required.');
}
$dir = dirname(__DIR__);
$config = $dir . '/private/config.php';
if (file_exists($config)) throw new RuntimeException('Refusing to overwrite existing config.php');
$settings = [
  'database' => ['host' => $host, 'port' => 3306, 'name' => $name, 'user' => $user, 'password' => $pass],
  'base_path' => '',
  'setup_key' => $host === 'preview-db' ? 'local-preview-only-nonproduction' : 'ci-test-only-nonproduction',
  'analytics_secret' => str_repeat('preview-ci-only-', 4),
];
file_put_contents($config, "<?php\nreturn " . var_export($settings, true) . ";\n");
$pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
$ddl = [
  "CREATE TABLE admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
  ) ENGINE=InnoDB",
  "CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attempts (ip_hash, attempted_at)
  ) ENGINE=InnoDB",
  "CREATE TABLE lots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lot_number INT UNSIGNED NOT NULL UNIQUE,
    title VARCHAR(250) NOT NULL,
    subtitle VARCHAR(250) NOT NULL DEFAULT '',
    category VARCHAR(120) NOT NULL DEFAULT 'Overige',
    description TEXT NOT NULL,
    details TEXT NOT NULL,
    estimate_min DECIMAL(12,2) DEFAULT NULL,
    estimate_max DECIMAL(12,2) DEFAULT NULL,
    created_by_admin_id INT NULL DEFAULT NULL,
    auction_only TINYINT(1) NOT NULL DEFAULT 0,
    market_price DECIMAL(12,2) DEFAULT NULL,
    starting_bid DECIMAL(12,2) DEFAULT NULL,
    status ENUM('draft','published','sold','canceled') NOT NULL DEFAULT 'draft',
    sale_location ENUM('markt','veiling') NOT NULL DEFAULT 'markt',
    auction_number INT UNSIGNED NULL DEFAULT NULL,
    UNIQUE KEY uq_lots_auction_number (auction_number),
    CONSTRAINT chk_lots_auction_number_positive CHECK (auction_number IS NULL OR auction_number >= 1),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_public (status,lot_number)
  ) ENGINE=InnoDB",
  "CREATE TABLE issued_lot_numbers (
    lot_number INT UNSIGNED NOT NULL PRIMARY KEY,
    issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB",
  "CREATE TABLE lot_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lot_id INT UNSIGNED NOT NULL,
    path VARCHAR(255) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    source_name VARCHAR(200) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_source (lot_id, source_name),
    KEY idx_img (lot_id,sort_order,id),
    CONSTRAINT fk_lot_image FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE
  ) ENGINE=InnoDB",
  "CREATE TABLE settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    subtitle VARCHAR(220) NOT NULL DEFAULT '',
    introduction TEXT NOT NULL,
    event_date DATE DEFAULT NULL,
    event_time VARCHAR(90) NOT NULL DEFAULT '',
    location VARCHAR(190) NOT NULL DEFAULT '',
    address VARCHAR(300) NOT NULL DEFAULT '',
    contact_email VARCHAR(190) NOT NULL DEFAULT '',
    practical_info TEXT NOT NULL,
    is_published TINYINT NOT NULL DEFAULT 0,
    catalog_split_active TINYINT(1) NOT NULL DEFAULT 0
  ) ENGINE=InnoDB",
];
foreach ($ddl as $sql) $pdo->exec($sql);
// Exercise the exact reviewed artist migration on the disposable MySQL 8.4 database.
$artistMigration = file_get_contents($dir . '/database/migrations/20261108_lot_artist_fields.sql');
if (!is_string($artistMigration) || !str_contains($artistMigration, 'chk_lots_artist_url_requires_name')) {
  throw new RuntimeException('Artist migration fixture is missing.');
}
$pdo->exec($artistMigration);
// Apply the proposed one-time category migration to the isolated test database.
$categorySql = file_get_contents($dir . '/database/migrations/20261110_lot_categories.sql');
if (!is_string($categorySql) || !str_contains($categorySql, "UPDATE lots SET category=''")) {
  throw new RuntimeException('Missing reviewed categories migration');
}
$categorySql = preg_replace('/^\s*--[^\n]*$/m', '', $categorySql);
foreach (explode(';', (string)$categorySql) as $statement) {
  if (trim($statement) !== '') $pdo->exec($statement);
}
$seeded = $pdo->query('SELECT name FROM lot_categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
if ($seeded !== ['Moderne Kunst','Oude Meesters']) throw new RuntimeException('Incorrect category seed data');
// Apply reviewed bank fields only to disposable integration database.
$bankMigration = file_get_contents($dir . '/database/migrations/20261110_lot_bank_fields.sql');
if (!is_string($bankMigration) || !str_contains($bankMigration, 'ADD COLUMN bank_account_iban')) {
  throw new RuntimeException('Bank fields fixture is missing.');
}
$pdo->exec($bankMigration);
if (count($pdo->query("SHOW COLUMNS FROM lots WHERE Field IN ('bank_account_name','bank_account_iban')")->fetchAll()) !== 2) {
  throw new RuntimeException('Bank fields fixture missing database columns.');
}
// Execute proposed private seller migration exclusively in disposable CI database.
$sellerSql = file_get_contents($dir . '/database/migrations/20261111_lot_sellers.sql');
if (!is_string($sellerSql) || !str_contains($sellerSql, 'fk_lots_seller')) {
  throw new RuntimeException('Missing reviewed seller migration');
}
$sellerSql = preg_replace('/^\s*--[^\n]*$/m', '', $sellerSql);
foreach (explode(';', (string)$sellerSql) as $statement) {
  if (trim($statement) !== '') $pdo->exec($statement);
}
// Artist profile schema is additive; use only the disposable auction_test database.
$artistProfileSql=file_get_contents($dir.'/database/migrations/20261111_seller_artist_profile.sql');
if (!is_string($artistProfileSql) || !str_contains($artistProfileSql,'fk_seller_images_seller')) {
  throw new RuntimeException('Artist profile migration fixture missing.');
}
$artistProfileSql=preg_replace('/^\s*--[^\n]*$/m','',$artistProfileSql);
foreach(explode(';',(string)$artistProfileSql) as $statement) if(trim($statement)!=='') $pdo->exec($statement);
if (!$pdo->query("SHOW COLUMNS FROM lot_sellers LIKE 'artist_intro'")->fetch()
    || !$pdo->query("SHOW TABLES LIKE 'seller_images'")->fetchColumn()) {
  throw new RuntimeException('Artist profile schema missing in isolated test database');
}
// Mobile schema is additive and is applied only to the disposable CI DB.
$mobileSql=file_get_contents($dir.'/database/migrations/20261111_seller_mobile.sql');
if (!is_string($mobileSql) || !str_contains($mobileSql,'ADD COLUMN mobile_number VARCHAR(16)')) {
  throw new RuntimeException('Seller mobile migration fixture missing.');
}
$mobileSql=preg_replace('/^\s*--[^\n]*$/m','',$mobileSql);
$pdo->exec(trim((string)$mobileSql));
if (!$pdo->query("SHOW COLUMNS FROM lot_sellers LIKE 'mobile_number'")->fetch())
  throw new RuntimeException('Seller mobile number column missing in disposable CI DB');
// Exercise legacy artist constraint before the agreed destructive seller-dedup migration.
$artistColumns = $pdo->query("SHOW COLUMNS FROM lots WHERE Field IN ('artist_name', 'artist_url')")->fetchAll();
if (count($artistColumns) !== 2) throw new RuntimeException('Artist migration did not add both fields.');
try {
  $pdo->exec("INSERT INTO lots (lot_number,title,description,details,artist_url) VALUES (999999,'Invalid','','','https://example.org/')");
  throw new RuntimeException('Artist URL without name bypassed database constraint');
} catch (PDOException $expected) {
  if ((int)($expected->errorInfo[1] ?? 0) !== 3819) throw $expected;
}

// Option B approved: only remove these four obsolete lot metadata columns in isolated CI.
$dropSql = file_get_contents($dir . '/database/migrations/20261111_lot_seller_dedup.sql');
if (!is_string($dropSql) || !str_contains($dropSql, 'DROP COLUMN bank_account_iban')) throw new RuntimeException('Dedup migration missing');
$dropSql = preg_replace('/^\s*--[^\n]*$/m', '', $dropSql);
$pdo->exec(trim((string)$dropSql));
if ($pdo->query("SHOW COLUMNS FROM lots LIKE 'artist_name'")->fetch()
    || $pdo->query("SHOW COLUMNS FROM lots LIKE 'bank_account_iban'")->fetch()) {
  throw new RuntimeException('Obsolete per-lot seller metadata remains after dedup');
}
$hash = password_hash('ci-password-not-a-real-secret', PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO admins (email,password_hash) VALUES (?,?)')->execute(['ci-admin@example.invalid',$hash]);
// Exercise the actual reviewed migration on the disposable pre-name CI account.
$migration = file_get_contents($dir . '/database/admin_name_migration.sql');
if (!is_string($migration) ||
    !preg_match("/^ALTER TABLE admins ADD COLUMN name VARCHAR\\(120\\) NOT NULL DEFAULT '' AFTER email;$/m", $migration, $sqlMatch)) {
  throw new RuntimeException('Admin name migration SQL not found in reviewed file.');
}
$pdo->exec($sqlMatch[0]);
$afterMigration = $pdo->query("SELECT name,email FROM admins WHERE email='ci-admin@example.invalid'")->fetch(PDO::FETCH_ASSOC);
if (!$afterMigration || $afterMigration['name'] !== '' || $afterMigration['email'] !== 'ci-admin@example.invalid') {
  throw new RuntimeException('Migration did not preserve the existing CI account with an empty name.');
}
// Apply the proposed issue-354 schema exclusively to the disposable CI database.
$inviteMigration = file_get_contents($dir . '/database/admin_invitation_migration.sql');
if (!is_string($inviteMigration)) throw new RuntimeException('Missing invitation migration fixture.');
$pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'administrator'");
$pdo->exec('ALTER TABLE admins ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL');
if (!preg_match('/CREATE TABLE admin_invitations \\([\\s\\S]*?\\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/',
    $inviteMigration, $invitationDdl)) {
  throw new RuntimeException('Invitation table declaration missing in reviewed migration.');
}
$pdo->exec($invitationDdl[0]);
$pdo->exec("INSERT INTO settings (id,title,introduction,practical_info) VALUES (1,'CI catalogus','','')");
$ipMigration = file_get_contents($dir . '/database/migrations/20261102_visitor_stats_ip_exclusions.sql');
$ipMigration = preg_replace('/^\s*--[^\n]*$/m', '', (string)$ipMigration);
foreach (explode(';', (string)$ipMigration) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
echo "Isolated MySQL schema ready; production is not accessed.\n";
