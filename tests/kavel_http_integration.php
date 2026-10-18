<?php
declare(strict_types=1);
/**
 * Browser-level regression test against localhost PHP server + disposable MySQL.
 * No OVHcloud endpoints, secrets, or production accounts are used.
 */
require dirname(__DIR__) . '/private/bootstrap.php';

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
  $csrf = token($html);
  $invalid = [
    'csrf' => $csrf, 'action' => 'save', 'id' => '0',
    'lot_number' => '99', 'title' => 'Nieuwe titel <&>',
    'category' => 'Design', 'description' => 'Niet opgeslagen',
    'estimate_min' => '200', 'estimate_max' => '100', 'status' => 'published',
  ];
  [$status, $html] = request_page('/admin/kavel.php', $invalid);
  claim($status === 200 && str_contains($html,'Maximumschatting mag niet lager'), 'Invalid estimate rejected');
  claim(str_contains($html, 'value="Nieuwe titel &lt;&amp;&gt;"'), 'Submitted title restored and HTML escaped');
  claim(str_contains($html, 'name="estimate_min" value="200"') && str_contains($html,'name="estimate_max" value="100"'), 'Submitted estimates restored');
  claim(lot_count() === 0, 'No new lot after invalid estimate');

  db()->prepare("INSERT INTO lots (lot_number,title,description,details,status) VALUES (10,'Originele titel','','','draft')")->execute();
  $id = (int)by_number(10)['id'];
  $invalid['id'] = (string)$id; $invalid['lot_number'] = '10'; $invalid['title']='Gewijzigde maar niet opgeslagen titel';
  [$status, $html] = request_page('/admin/kavel.php?id=' . $id, $invalid);
  claim($status === 200 && str_contains($html, 'value="Gewijzigde maar niet opgeslagen titel"'), 'Edit error retains changed title');
  claim(by_number(10)['title'] === 'Originele titel', 'Invalid edit did not change saved title');

  $invalid['id'] = '0'; $invalid['estimate_min'] = '10'; $invalid['estimate_max'] = '20';
  $invalid['title'] = 'Dubbel lotnummer & tekst';
  [$status, $html] = request_page('/admin/kavel.php', $invalid);
  claim($status === 200 && str_contains($html,'lotnummer bestaat al'), 'Duplicate lot number rejected');
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

  echo "Kavel editor integration: {$count} checks passed on isolated localhost/MySQL.\n";
} finally {
  foreach (glob($fixtures.'/*') ?: [] as $item) @unlink($item);
  @rmdir($fixtures);
  @unlink($jar);
}
