<?php
declare(strict_types=1);
/** Create only an isolated CI database; never load production credentials. */
$host = getenv('CI_DB_HOST') ?: '127.0.0.1';
$name = getenv('CI_DB_NAME') ?: 'auction_test';
$user = getenv('CI_DB_USER') ?: 'root';
$pass = getenv('CI_DB_PASSWORD') ?: '';
if ($name !== 'auction_test' || $host !== '127.0.0.1') {
  throw new RuntimeException('Isolated-test database settings required.');
}
$dir = dirname(__DIR__);
$config = $dir . '/private/config.php';
if (file_exists($config)) throw new RuntimeException('Refusing to overwrite existing config.php');
$settings = [
  'database' => ['host' => $host, 'port' => 3306, 'name' => $name, 'user' => $user, 'password' => $pass],
  'base_path' => '',
  'setup_key' => 'ci-test-only-nonproduction',
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
    is_published TINYINT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB",
];
foreach ($ddl as $sql) $pdo->exec($sql);
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
$pdo->exec('ALTER TABLE admins ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL');
if (!preg_match('/CREATE TABLE admin_invitations \\([\\s\\S]*?\\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/',
    $inviteMigration, $invitationDdl)) {
  throw new RuntimeException('Invitation table declaration missing in reviewed migration.');
}
$pdo->exec($invitationDdl[0]);
$pdo->exec("INSERT INTO settings (id,title,introduction,practical_info) VALUES (1,'CI catalogus','','')");
echo "Isolated MySQL schema ready; production is not accessed.\n";
