<?php
declare(strict_types=1);
/**
 * Browser-level regression test against localhost PHP server + disposable MySQL.
 * No OVHcloud endpoints, secrets, or production accounts are used.
 */
require dirname(__DIR__) . '/private/bootstrap.php';
require dirname(__DIR__) . '/private/catalog_mutex.php';

// Apply role extension only to the disposable CI database used by this test.
if (getenv('GITHUB_ACTIONS') === 'true' && db()->query('SELECT DATABASE()')->fetchColumn() === 'auction_test') {
  $column = db()->query("SHOW COLUMNS FROM admins LIKE 'role'")->fetch();
  if (!$column) db()->exec("ALTER TABLE admins ADD COLUMN role ENUM('administrator','assistant') NOT NULL DEFAULT 'administrator'");
  if (!db()->query("SHOW COLUMNS FROM lots LIKE 'sale_location'")->fetch()) {
    db()->exec("ALTER TABLE lots ADD COLUMN sale_location ENUM('markt','veiling') NOT NULL DEFAULT 'markt', ADD COLUMN auction_number INT UNSIGNED NULL DEFAULT NULL, ADD UNIQUE KEY uq_lots_auction_number (auction_number), ADD CONSTRAINT chk_lots_auction_number_positive CHECK (auction_number IS NULL OR auction_number >= 1)");
  }

}


$base = 'http://localhost:8090';
$jar = tempnam(sys_get_temp_dir(), 'catalog-ci-cookie');
$fixtures = sys_get_temp_dir() . '/catalog-ci-' . bin2hex(random_bytes(6));
mkdir($fixtures);
$count = 0;

function claim(bool $ok, string $message): void {
  global $count;
  ++$count;
  if (!$ok) throw new RuntimeException("FAILED {$message}");
  echo "PASS {$message}\n";
}
function request_page(string $url, ?array $data = null, bool $multipart = false): array {
  global $base, $jar;
  $ch = curl_init($base . $url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HEADER => false,
  ]);
  if ($data !== null) {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
  }
  $html = curl_exec($ch);
  if ($html === false) throw new RuntimeException('HTTP client failed: ' . curl_error($ch));
  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$status, (string)$html];
}
function token(string $html): string {
  if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m)) {
    throw new RuntimeException('CSRF token missing on page');
  }
  return $m[1];
}
function lot_count(): int { return (int)db()->query('SELECT COUNT(*) FROM lots')->fetchColumn(); }
function by_number(int $nr): array|false {
  $p = db()->prepare('SELECT * FROM lots WHERE lot_number=?');
  $p->execute([$nr]);
  return $p->fetch();
}

try {
  [$status] = request_page('/admin/fotos-bulk.php');
  claim($status === 404, 'Unauthenticated bulk import GET returns 404');
  [$status] = request_page('/admin/fotos-bulk.php', ['action' => 'process']);
  claim($status === 404, 'Unauthenticated bulk import POST returns 404');

  [$status, $html] = request_page('/admin/login.php');
  claim($status === 200, 'Login form loads');
  $csrf = token($html);
  [$status] = request_page('/admin/login.php', [
    'csrf' => $csrf,
    'email' => 'ci-admin@example.invalid',
    'password' => 'ci-password-not-a-real-secret',
  ]);
  claim($status === 303, 'Valid login redirects');

  [$status, $html] = request_page('/admin/kavel.php');
  claim($status === 200 && str_contains($html,'lot-editor-form'), 'Authenticated kavel editor reachable');
  // A concurrent snapshot/restore owns the MySQL lock: valid POST must
  // fail closed before either SQL or photo-file mutations.
  catalog_data_lock(db(), 0);
  try {
    [$status, $blockedBody] = request_page('/admin/kavel.php', [
      'csrf' => token($html), 'action' => 'save',
      'lot_number' => '876543', 'title' => 'Must not be inserted'
    ]);
    claim($status === 503 && !by_number(876543)
        && str_contains($blockedBody, 'tijdelijk bezig'),
        'T-051 concurrent backup lock rejects lot POST with HTTP 503 and no DB mutation');
  } finally {
    catalog_data_unlock(db());
  }

  claim(str_contains($html, 'class="admin-logout-form"') && str_contains($html, 'action="/admin/logout.php"') && !str_contains($html, 'href="/admin/logout.php"'), 'Navigation uses a POST logout form, never a GET link');
  claim(!str_contains($html, 'Foto-import') && !str_contains($html, 'fotos-bulk.php'), 'Bulk photo import absent from admin navigation');
  [$status, $systemcheck] = request_page('/admin/systeemcheck.php');
  claim($status === 200 && !str_contains($systemcheck, 'Afgeschermde fotobulkmap') && !str_contains($systemcheck, 'bulk via SFTP'), 'System check no longer requires bulk photo import');
  [$status] = request_page('/admin/fotos-bulk.php');
  claim($status === 404, 'Old bulk photo import GET returns 404');
  $lotsBeforeBulk = lot_count();
  [$status] = request_page('/admin/fotos-bulk.php', ['action' => 'process', 'csrf' => 'unused']);
  claim($status === 404 && lot_count() === $lotsBeforeBulk, 'Old bulk photo import POST returns 404 without lot changes');
  $csrf = token($html);
  $invalid = [
    'csrf' => $csrf, 'action' => 'save', 'id' => '0',
    'lot_number' => '99', 'title' => 'Nieuwe titel <&>',
    'category' => 'Design', 'description' => 'Niet opgeslagen',
    'market_price' => '-1', 'starting_bid' => '100', 'status' => 'published',
  ];
  [$status, $html] = request_page('/admin/kavel.php', $invalid);
  claim($status === 200 && str_contains($html,'Ongeldige marktprijs.'), 'Invalid market price rejected');
  claim(str_contains($html, 'value="Nieuwe titel &lt;&amp;&gt;"'), 'Submitted title restored and HTML escaped');
  claim(str_contains($html, 'name="market_price" value="-1"') && str_contains($html,'name="starting_bid" value="100"'), 'Submitted market price and starting bid restored');
  claim(lot_count() === 0, 'No new lot after invalid valuation');

  db()->prepare("INSERT INTO lots (lot_number,title,description,details,status) VALUES (10,'Originele titel','','','draft')")->execute();
  $id = (int)by_number(10)['id'];
  $invalid['id'] = (string)$id; $invalid['lot_number'] = '10'; $invalid['title']='Gewijzigde maar niet opgeslagen titel';
  [$status, $html] = request_page('/admin/kavel.php?id=' . $id, $invalid);
  claim($status === 200 && str_contains($html, 'value="Gewijzigde maar niet opgeslagen titel"'), 'Edit error retains changed title');
  claim(by_number(10)['title'] === 'Originele titel', 'Invalid edit did not change saved title');

  $invalid['id'] = '0'; $invalid['market_price'] = '10'; $invalid['starting_bid'] = '20';
  $invalid['title'] = 'Dubbel lotnummer & tekst';
  [$status, $html] = request_page('/admin/kavel.php', $invalid);
  claim($status === 200 && str_contains($html,'lotnummer is al uitgegeven'), 'Duplicate lot number rejected');
  claim(str_contains($html,'value="Dubbel lotnummer &amp; tekst"'), 'Duplicate error retains submitted title');
  claim(lot_count() === 1, 'Duplicate did not create lot');

  file_put_contents($fixtures . '/big.txt', str_repeat('x', 2200));
  $valid = [
    'csrf' => $csrf, 'action' => 'save', 'id' => '0', 'lot_number' => '20',
    'title' => 'Upload te groot', 'category' => 'Overige',
    'description' => '', 'details' => '', 'estimate_min' => '', 'estimate_max' => '', 'status' => 'draft',
  ];
  $big = $valid;
  $big['photos[0]'] = new CURLFile($fixtures . '/big.txt', 'text/plain', 'big.txt');
  [$status, $html] = request_page('/admin/kavel.php', $big, true);
  claim($status === 200 && str_contains($html,'Foto-upload mislukt; het kavel is niet opgeslagen'), 'Upload status rejected before SQL');
  claim(!by_number(20), 'Oversize upload did not create lot');

  // A valid 32x32 PNG exercises the positive image path on temporary CI storage.
  $image = imagecreatetruecolor(32, 32);
  imagefill($image, 0, 0, imagecolorallocate($image, 35, 80, 105));
  imagepng($image, $fixtures . '/valid.png');
  imagedestroy($image);
  $small = $valid;
  $small['title'] = 'Geldig fotokavel';
  $small['photos[0]'] = new CURLFile($fixtures . '/valid.png', 'image/png', 'valid.png');
  [$status] = request_page('/admin/kavel.php', $small, true);
  claim($status === 303 && (bool)by_number(20), 'Valid kavel and photo saved');
  $st = db()->prepare('SELECT path FROM lot_images WHERE lot_id=?');
  $st->execute([(int)by_number(20)['id']]);
  $imagePath = (string)$st->fetchColumn();
  claim($imagePath !== '' && is_file(dirname(__DIR__) . '/catalogus-public/' . $imagePath), 'Photo DB and file linked');
  // Keep workspace clean after check.
  @unlink(dirname(__DIR__) . '/catalogus-public/' . $imagePath);

  $bad = $valid;
  $bad['lot_number'] = '30'; $bad['title'] = 'Inhoud geen foto';
  file_put_contents($fixtures . '/bad.txt','invalid image bytes');
  $bad['photos[0]'] = new CURLFile($fixtures . '/bad.txt', 'image/png', 'bad.png');
  [$status] = request_page('/admin/kavel.php', $bad, true);
  claim($status === 303 && (bool)by_number(30), 'Bad image content preserves existing partial-save behavior');
  [$status, $html] = request_page('/admin/kavel.php?id=' . by_number(30)['id']);
  claim($status === 200 && str_contains($html,'sommige foto'), 'Partial save warning displayed');

  [$status] = request_page('/admin/import.php', ['action'=>'save']);
  claim($status === 404, 'CSV import stays disabled');
  [$status] = request_page('/admin/kavel.php', [
    'action'=>'save', 'lot_number'=>'40', 'title'=>'No csrf'
  ]);
  claim($status === 403 && !by_number(40), 'CSRF rejection and no write');

  // Auction numbers are unique and monotonically assigned in the isolated MySQL fixture.
  [$status, $form] = request_page('/admin/kavel.php');
  claim($status === 200 && str_contains($form, 'name="sale_location"'), 'Sale location selector visible');
  [$status] = request_page('/admin/kavel.php', [
    'csrf'=>token($form), 'action'=>'save', 'lot_number'=>'301',
    'title'=>'Auction one', 'sale_location'=>'veiling'
  ]);
  claim($status===303 && (int)by_number(301)['auction_number']===1,
    'First auction assignment starts at 1');
  [$status, $form] = request_page('/admin/kavel.php');
  [$status] = request_page('/admin/kavel.php', [
    'csrf'=>token($form), 'action'=>'save', 'lot_number'=>'302',
    'title'=>'Auction two', 'sale_location'=>'veiling'
  ]);
  claim($status===303 && (int)by_number(302)['auction_number']===2,
    'Second auction number increments');
  $auctionId=(int)by_number(301)['id'];
  [$status, $form] = request_page('/admin/kavel.php?id='.$auctionId);
  [$status] = request_page('/admin/kavel.php?id='.$auctionId, [
    'csrf'=>token($form), 'action'=>'save','id'=>(string)$auctionId,
    'lot_number'=>'301','title'=>'Auction one', 'sale_location'=>'markt'
  ]);
  claim($status===303 && by_number(301)['auction_number']===null &&
    by_number(301)['sale_location']==='markt' &&
    (int)by_number(302)['auction_number']===1,
    'Market reassignment clears auction number and closes the gap');
  [$status, $form] = request_page('/admin/kavel.php?id='.$auctionId);
  [$status] = request_page('/admin/kavel.php?id='.$auctionId, [
    'csrf'=>token($form), 'action'=>'save','id'=>(string)$auctionId,
    'lot_number'=>'301','title'=>'Auction one', 'sale_location'=>'veiling'
  ]);
  claim($status===303 && (int)by_number(301)['auction_number']===2,
    'Returning to auction gets next available number');
  try {
    db()->exec("UPDATE lots SET auction_number=2 WHERE lot_number=302");
    claim(false,'Database unique constraint must reject duplicate auction number');
  } catch (PDOException $e) {
    claim($e->getCode()==='23000','Database rejects duplicate auction number');
  }

  // Auction lots may be sold, but must move to market in a separate save before cancellation.
  [$httpStatus, $editor] = request_page('/admin/kavel.php?id='.$auctionId);
  [$httpStatus] = request_page('/admin/kavel.php?id='.$auctionId, [
    'csrf'=>token($editor), 'action'=>'save', 'id'=>(string)$auctionId,
    'lot_number'=>'301', 'title'=>'Auction one', 'sale_location'=>'veiling', 'status'=>'sold'
  ]);
  claim($httpStatus===303 && by_number(301)['status']==='sold', 'Auction lot may be sold');
  [$httpStatus, $editor] = request_page('/admin/kavel.php?id='.$auctionId);
  [$httpStatus, $body] = request_page('/admin/kavel.php?id='.$auctionId, [
    'csrf'=>token($editor), 'action'=>'save', 'id'=>(string)$auctionId,
    'lot_number'=>'301', 'title'=>'Blocked cancel', 'sale_location'=>'veiling', 'status'=>'canceled'
  ]);
  claim($httpStatus===200 && str_contains($body,'eerst afzonderlijk over naar Markt') &&
    by_number(301)['status']==='sold' && by_number(301)['title']==='Auction one',
    'Auction lot cannot be canceled');
  [$httpStatus, $editor] = request_page('/admin/kavel.php?id='.$auctionId);
  [$httpStatus, $body] = request_page('/admin/kavel.php?id='.$auctionId, [
    'csrf'=>token($editor), 'action'=>'save', 'id'=>(string)$auctionId,
    'lot_number'=>'301', 'title'=>'Blocked combined cancel', 'sale_location'=>'markt', 'status'=>'canceled'
  ]);
  claim($httpStatus===200 && str_contains($body,'eerst afzonderlijk over naar Markt') &&
    by_number(301)['sale_location']==='veiling' && by_number(301)['status']==='sold',
    'Cannot bypass two-step flow by combining transfer and cancel');
  [$httpStatus, $editor] = request_page('/admin/kavel.php?id='.$auctionId);
  [$httpStatus] = request_page('/admin/kavel.php?id='.$auctionId, [
    'csrf'=>token($editor), 'action'=>'save', 'id'=>(string)$auctionId,
    'lot_number'=>'301', 'title'=>'Auction one', 'sale_location'=>'markt', 'status'=>'sold'
  ]);
  claim($httpStatus===303 && by_number(301)['sale_location']==='markt' &&
    by_number(301)['auction_number']===null && (int)by_number(302)['auction_number']===1,
    'Separate market transfer clears auction number and compacts sequence');
  [$httpStatus, $editor] = request_page('/admin/kavel.php?id='.$auctionId);
  [$httpStatus] = request_page('/admin/kavel.php?id='.$auctionId, [
    'csrf'=>token($editor), 'action'=>'save', 'id'=>(string)$auctionId,
    'lot_number'=>'301', 'title'=>'Auction one', 'sale_location'=>'markt', 'status'=>'canceled'
  ]);
  claim($httpStatus===303 && by_number(301)['status']==='canceled' &&
    by_number(301)['auction_number']===null, 'Market lot can be canceled after transfer');
  [$httpStatus, $editor] = request_page('/admin/kavel.php?id='.$auctionId);
  claim($httpStatus===200 && str_contains($editor,'value="canceled" selected'),
    'Canceled market status shown after reload');

  // Role and ownership checks against the real HTTP endpoint and isolated database.
  $actor = (int)db()->query("SELECT id FROM admins WHERE email='ci-admin@example.invalid'")->fetchColumn();
  db()->prepare("INSERT INTO lots (lot_number,title,description,details,status,created_by_admin_id) VALUES (401,'Own draft','','','draft',?)")->execute([$actor]);
  db()->exec("INSERT INTO lots (lot_number,title,description,details,status,created_by_admin_id) VALUES (402,'Other draft','','','draft',99999)");
  db()->prepare("INSERT INTO lots (lot_number,title,description,details,status,created_by_admin_id) VALUES (403,'Own published','','','published',?)")->execute([$actor]);
  db()->prepare("UPDATE admins SET role='assistant' WHERE id=?")->execute([$actor]);
  [$status, $ownForm] = request_page('/admin/kavel.php?id='.(int)by_number(401)['id']);
  claim($status===200 && !str_contains($ownForm,'alleen-lezen'), 'Assistant may open own draft for editing');
  $csrfAssistant = token($ownForm);
  [$status] = request_page('/admin/kavel.php?id='.(int)by_number(401)['id'], [
    'csrf'=>$csrfAssistant,'action'=>'save','id'=>(string)by_number(401)['id'],
    'lot_number'=>'401','title'=>'Own edited','status'=>'published','sale_location'=>'markt'
  ]);
  claim($status===303 && by_number(401)['title']==='Own edited' && by_number(401)['status']==='draft',
    'Assistant edits own draft but cannot publish by forged POST');
  [$status, $otherForm] = request_page('/admin/kavel.php?id='.(int)by_number(402)['id']);
  claim($status===200 && str_contains($otherForm,'alleen-lezen'), 'Other assistant draft is read-only');
  [$status] = request_page('/admin/kavel.php?id='.(int)by_number(402)['id'], [
    'csrf'=>$csrfAssistant,'action'=>'save','id'=>(string)by_number(402)['id'],
    'lot_number'=>'402','title'=>'FORGED'
  ]);
  claim($status===403 && by_number(402)['title']==='Other draft', 'Forged update of other draft blocked');
  [$status] = request_page('/admin/kavel.php?id='.(int)by_number(403)['id'], [
    'csrf'=>$csrfAssistant,'action'=>'delete-lot','id'=>(string)by_number(403)['id']
  ]);
  claim($status===403 && (bool)by_number(403), 'Assistant cannot remove published own lot');
  db()->prepare("UPDATE admins SET role='administrator' WHERE id=?")->execute([$actor]);
  [$status, $adminForm] = request_page('/admin/kavel.php?id='.(int)by_number(401)['id']);
  claim($status===200, 'Administrator regains editor');
  [$status, $conflict] = request_page('/admin/kavel.php?id='.(int)by_number(401)['id'], [
    'csrf'=>token($adminForm),'action'=>'save','id'=>(string)by_number(401)['id'],
    'lot_number'=>'401','title'=>'Blocked publication','status'=>'published',
    'sale_location'=>'markt','auction_only'=>'1'
  ]);
  claim($status===200 && str_contains($conflict,'Publicatie afgebroken') &&
    by_number(401)['status']==='draft' && by_number(401)['title']==='Own edited',
    'Auction-only on market aborts entire publication transaction');

  // Logout is a state-changing action and must require a valid POST + CSRF.
  [$status] = request_page('/admin/logout.php');
  claim($status === 405, 'Authenticated GET logout is rejected without destroying session');
  [$status, $stillLoggedIn] = request_page('/admin/kavel.php');
  claim($status === 200 && str_contains($stillLoggedIn, 'lot-editor-form'), 'GET logout keeps authenticated session');
  [$status] = request_page('/admin/logout.php', ['csrf' => 'invalid']);
  claim($status === 403, 'Logout POST without a valid CSRF token is refused');
  [$status, $stillLoggedIn] = request_page('/admin/kavel.php');
  claim($status === 200 && str_contains($stillLoggedIn, 'lot-editor-form'), 'Rejected logout POST preserves authenticated session');
  [$status] = request_page('/admin/logout.php', ['csrf' => token($stillLoggedIn)]);
  claim($status === 303, 'Valid logout POST redirects to login');
  [$status] = request_page('/admin/kavel.php');
  claim($status === 303, 'Admin route is protected after logout');
  [$status] = request_page('/admin/logout.php');
  claim($status === 303, 'Unauthenticated logout remains behind login');

  echo "Kavel editor integration: {$count} checks passed on isolated localhost/MySQL.\n";
} finally {
  foreach (glob($fixtures.'/*') ?: [] as $item) @unlink($item);
  @rmdir($fixtures);
  @unlink($jar);
}
