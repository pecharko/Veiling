<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Brussels');

// Fail closed on every PHP entry point after an interrupted restore.
// This checks only private filesystem markers; no DB/hosting credentials needed.
require_once __DIR__ . '/restore_journal.php';
restore_journal_gate_web();

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
  http_response_code(503);
  exit('Installatie nog niet voltooid. Zie de OVHcloud-handleiding.');
}
$GLOBALS['catalog_cfg'] = require $configFile;

function cfg(string $key): mixed {
  return $GLOBALS['catalog_cfg'][$key] ?? null;
}
function url(string $path = '', array $params = []): string {
  $base = rtrim((string) (cfg('base_path') ?? ''), '/');
  $path = ltrim($path, '/');
  $out = $base . '/' . $path;
  return $out . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}
function e(mixed $text): string {
  return htmlspecialchars((string)($text ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $c = cfg('database');
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)($c['port'] ?? 3306) .
      ';dbname=' . $c['name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['user'], $c['password'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  }
  return $pdo;
}
function settings(): array {
  $s = db()->query('SELECT * FROM settings WHERE id=1')->fetch();
  return $s ?: [];
}
/**
 * Public entry-point guard. An installation without the new column remains online
 * until the separately approved database migration is performed.
 */
function require_public_site_online(): void {
  header('Vary: Cookie', false);
  admin_preview_active(); // Validate preview identity before public output/tracking.
  $s = settings();
  if ((int)($s['is_site_online'] ?? 1) === 1) return;
  http_response_code(503);
  header('Cache-Control: no-store, max-age=0');
  header('X-Robots-Tag: noindex, nofollow');
  header('Retry-After: 3600');
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Binnenkort online · 41 Lokeren</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,sans-serif;color:#29473e;background:#fff9f5;text-align:center}main{max-width:620px;padding:32px}h1{font-size:clamp(2rem,6vw,3.5rem);color:#850015}p{line-height:1.7}</style></head><body><main><p><strong>41 LOKEREN</strong></p><h1>Binnenkort online</h1><p>We bereiden onze veilingwebsite voor. Kom binnenkort opnieuw kijken.</p></main></body></html>';
  exit;
}

function money(mixed $amount): string {
  return '€ ' . number_format((float)$amount, 2, ',', '.');
}
function estimate(array $lot): string {
  $market = $lot['market_price'] ?? null;
  $starting = $lot['starting_bid'] ?? null;
  if ($market === null && $starting === null) return 'Geen waardebepaling';
  $parts = [];
  if ($market !== null) $parts[] = 'Marktprijs: ' . money($market);
  if ($starting !== null) $parts[] = 'Startbod: ' . money($starting);
  return implode(' · ', $parts);
}
function date_nl(?string $date): string {
  if (!$date) return 'Nog te bepalen';
  $ts = strtotime($date);
  if ($ts === false) return 'Nog te bepalen';
  $months = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli',
    'augustus', 'september', 'oktober', 'november', 'december'];
  return date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function redirect(string $path, array $params = []): never {
  header('Location: ' . url($path, $params), true, 303); exit;
}
function stored_admin_role(): string {
  $st = db()->prepare('SELECT role FROM admins WHERE id=?');
  $st->execute([$_SESSION['admin_id']]);
  return (string)$st->fetchColumn();
}
function admin_role_switch_targets(string $stored): array {
  return match ($stored) {
    'administrator' => ['administrator', 'assistant', 'veilingmeester'],
    'veilingmeester' => ['veilingmeester', 'assistant'],
    default => [],
  };
}
function effective_admin_role(): string {
  $stored = stored_admin_role();
  $override = $_SESSION['admin_view_role'] ?? $stored;
  return is_string($override) && in_array($override, admin_role_switch_targets($stored), true)
    ? $override : $stored;
}
function can_edit_auction(): bool {
  return in_array(effective_admin_role(), ['administrator', 'veilingmeester'], true);
}
function require_auth(): void {
  admin_session();
  if (!isset($_SESSION['admin_id']) || !is_int($_SESSION['admin_id'])) redirect('admin/login.php');
  $st = db()->prepare('SELECT password_hash, is_active FROM admins WHERE id=?');
  $st->execute([$_SESSION['admin_id']]);
  $account = $st->fetch();
  $hash = $account['password_hash'] ?? null;
  $proof = (string)($_SESSION['admin_credential_hash'] ?? '');
  if (!is_string($hash) || (int)($account['is_active'] ?? 0) !== 1 || $proof === '' || !hash_equals(hash('sha256', $hash), $proof)) {
    $_SESSION = [];
    session_regenerate_id(true);
    redirect('admin/login.php');
  }
  $role = effective_admin_role();
  if (!in_array($role, ['administrator', 'assistant', 'veilingmeester'], true)) {
    http_response_code(403);
    exit('Onbekende beheerdersrol.');
  }
  $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  if (str_contains($script, '/admin/') && $role !== 'administrator') {
    $page = basename($script);
    $allowed = ['index.php', 'kavel.php', 'veiling-organisatie.php', 'rollen.php', 'logout.php'];
    // The original administrator or veilingmeester can always restore their role.
    if (admin_role_switch_targets(stored_admin_role()) !== []) $allowed[] = 'rol-wissel.php';
    if ($role === 'veilingmeester') $allowed[] = 'categorieen.php';
    if (!in_array($page, $allowed, true)) {
      http_response_code(403);
      exit('Geen toegang tot dit beheeronderdeel.');
    }
  }
}
function require_administrator(): void {
  require_auth();
  if (effective_admin_role() !== 'administrator') {
    http_response_code(403);
    exit('Geen toegang tot dit beheeronderdeel.');
  }
}
function admin_session(): void {
  if (session_status() !== PHP_SESSION_NONE) return;
  ini_set('session.use_strict_mode', '1');
  session_name('catalog_admin');
  session_set_cookie_params([
    'httponly' => true,
    'secure' => true, // beheerderscookies uitsluitend via HTTPS
    'samesite' => 'Lax',
    'path' => rtrim((string)(cfg('base_path') ?? ''), '/') . '/',
  ]);
  session_start();
}

/** Public visitors never receive a new admin session just to check preview. */
function admin_preview_session_present(): bool {
  if (session_status() === PHP_SESSION_ACTIVE) return session_name() === 'catalog_admin';
  if (session_status() !== PHP_SESSION_NONE || !is_string($_COOKIE['catalog_admin'] ?? null)
      || $_COOKIE['catalog_admin'] === '') return false;
  admin_session();
  return session_status() === PHP_SESSION_ACTIVE && session_name() === 'catalog_admin';
}

/** The flag is server-side only; every preview request revalidates the account. */
function admin_preview_active(): bool {
  static $verified = false;
  if (!admin_preview_session_present()) return false;
  $id = $_SESSION['admin_id'] ?? null;
  if (!is_int($id) || $id <= 0 || ($_SESSION['admin_catalog_preview'] ?? null) !== $id) return false;
  if (!$verified) {
    require_auth(); // Existing active-account, password-proof and role checks.
    require_no_index();
    $verified = true;
  }
  return true;
}

function admin_preview_banner(): void {
  if (!admin_preview_active()) return;
  require_once __DIR__ . '/catalog_preview_modes.php';
  $splitIsOn = catalog_split_active(settings());
  require_once __DIR__ . '/analytics_control.php';
  try { $excluded = analytics_ip_is_excluded(db()); }
  catch (Throwable $e) { $excluded = true; } // Tracker also fails closed on unavailable state.
  $statistics = $excluded ? 'Voor dit IP-adres worden geen bezoekersstatistieken verzameld.'
    : 'IP-uitsluiting staat uit of dit IP-adres is niet opgeslagen. Statistieken kunnen worden verzameld.';
  echo '<style>.admin-preview-banner{position:sticky;top:0;z-index:110}.admin-preview-banner~.site-header{top:var(--admin-preview-height,0px)!important}</style>'
    . '<aside class="admin-preview-banner" role="status" aria-label="Adminvoorbeeld" style="background:#fff3cc;color:#332900;padding:12px 20px;border-bottom:2px solid #9b7300;font:15px/1.5 system-ui;display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap">'
    . '<strong>ADMINVOORBEELD</strong><span>Kavels zijn zichtbaar voor deze beheersessie. ' . e($statistics) . '</span>'
    . '<form method="post" action="' . e(url('index.php')) . '" style="display:inline-flex;gap:6px;align-items:center;margin:0">' . csrf_field()
    . '<button name="admin_preview_action" value="split_on" type="submit" style="font:inherit;padding:4px 10px;border:1px solid #634300;border-radius:5px;cursor:pointer;background:' . ($splitIsOn ? '#ead495' : '#fff') . '">Opsplitsing aan</button>'
    . '<button name="admin_preview_action" value="split_off" type="submit" style="font:inherit;padding:4px 10px;border:1px solid #634300;border-radius:5px;cursor:pointer;background:' . ($splitIsOn ? '#fff' : '#ead495') . '">Opsplitsing uit</button></form>'
    . '<a href="' . e(url('admin/index.php')) . '" style="color:inherit;text-decoration:underline">Terug naar beheer</a>'
    . '<form method="post" action="' . e(url('index.php')) . '" style="margin:0"><input type="hidden" name="admin_preview_action" value="stop">'
    . csrf_field() . '<button type="submit" style="font:inherit;color:inherit;background:transparent;border:1px solid currentColor;border-radius:4px;padding:4px 10px;cursor:pointer">Voorbeeld sluiten</button></form></aside>'
    . '<script>(()=>{const banner=document.querySelector(".admin-preview-banner");const size=()=>document.documentElement.style.setProperty("--admin-preview-height",banner.offsetHeight+"px");size();if(window.ResizeObserver)new ResizeObserver(size).observe(banner);else window.addEventListener("resize",size);})();</script>';
}
function csrf_token(): string {
  admin_session();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
  return (string)$_SESSION['csrf'];
}
function csrf_field(): string {
  return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function check_csrf(): void {
  admin_session();
  $received = (string)($_POST['csrf'] ?? '');
  if (!$received || !hash_equals(csrf_token(), $received)) {
    http_response_code(403); exit('Ongeldige formulierbeveiliging. Vernieuw de pagina.');
  }
}
function flash(string $message, string $type = 'success'): void {
  admin_session();
  $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function take_flash(): ?array {
  admin_session();
  $f = $_SESSION['flash'] ?? null;
  unset($_SESSION['flash']);
  return $f;
}
function require_no_index(): void {
  header('X-Robots-Tag: noindex, nofollow');
  header('Cache-Control: no-store, private');
  header('Pragma: no-cache');
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
}

function admin_top(string $title): void {
  require_no_index();
  $flash = take_flash();
  $home = e(url('admin/index.php'));
  $public = e(url('index.php'));
  $publicPreview = '<form method="post" action="' . $public . '" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;margin:0"><input type="hidden" name="admin_preview_action" value="start">' . csrf_field() . '<button class="admin-logout-button" type="submit">Bekijk website ↗</button></form>';
  $edit = e(url('admin/kavel.php'));
  $auctionOrganise = e(url('admin/veiling-organisatie.php'));
  $settings = e(url('admin/instellingen.php'));
  $check = e(url('admin/systeemcheck.php'));
  $backup = e(url('admin/backup.php'));
  $stats = e(url('admin/statistieken.php'));
  $logout = e(url('admin/logout.php'));
  $users = e(url('admin/gebruikers.php'));
  $isAdministrator = effective_admin_role() === 'administrator';
  $canSwitchRole = admin_role_switch_targets(stored_admin_role()) !== [];
  $roleSwitch = e(url('admin/rol-wissel.php'));
  $rolesHelp = e(url('admin/rollen.php'));
  $activeRole = effective_admin_role();
  $roleLabels = ['administrator' => 'Administrator', 'assistant' => 'Assistent', 'veilingmeester' => 'Veilingmeester'];
  $activeRoleLabel = e($roleLabels[$activeRole] ?? $activeRole);
  $roleIndicator = '<span class="admin-active-role" aria-label="Actieve rol: ' . $activeRoleLabel . '">Rol: ' . $activeRoleLabel . '</span>';
  $reportIssue = '<a class="admin-report-issue" href="https://github.com/pecharko/Veiling/issues/new/choose" target="_blank" rel="noopener noreferrer" aria-label="Probleem of verbetering melden op GitHub (opent in nieuw tabblad)">⚠ Probleem melden</a>';
  $roleSwitchNav = $canSwitchRole ? '<a href="' . $roleSwitch . '">Rol wisselen (' . e(effective_admin_role()) . ')</a>' : '';
  echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . ' · Beheer veiling</title><link rel="icon" type="image/png" href="' . e(url('favicon.png')) . '"><link rel="stylesheet" href="' . e(url('admin/admin.css')) . '"></head><body><header class="admin-header"><div class="admin-brand-row"><a href="' . $home . '" class="admin-logo">Website Beheer</a>' . $roleIndicator . $reportIssue . '</div><nav><a href="' . $home . '">Kavels</a><a href="' . $auctionOrganise . '">Veiling organisatie</a>' . ($isAdministrator ? '<a href="' . $settings . '">Veilinginfo</a><a href="' . $check . '">Systeemcheck</a><a href="' . $backup . '">Back-up &amp; herstel</a><a href="' . $stats . '">Statistieken</a><a href="' . $users . '">Gebruikersbeheer</a>' : '') . $publicPreview . '<a href="' . $rolesHelp . '">Rollen &amp; rechten</a>' . $roleSwitchNav . '<form class="admin-logout-form" method="post" action="' . $logout . '">' . csrf_field() . '<button class="admin-logout-button" type="submit">Uitloggen</button></form></nav></header><main class="admin-main"><div class="page-heading"><p class="eyebrow">VEILINGCATALOGUS · BEHEEROMGEVING</p><h1>' . e($title) . '</h1></div>';
  if ($flash) echo '<div class="alert ' . e($flash['type']) . '" role="status">' . e($flash['message']) . '</div>';
}
function admin_bottom(): void {
  echo '</main><footer class="admin-footer">Alleen bevoegde organisatoren hebben toegang. Maak regelmatig een back-up van de database en foto’s.</footer></body></html>';
}
