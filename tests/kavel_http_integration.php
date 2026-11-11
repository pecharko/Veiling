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
function request_page(string $url, ?array $data = null, bool $multipart = false, array $headers = []): array {
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
    CURLOPT_HTTPHEADER => $headers,
  ]);
  if ($data !== null) {
    // Existing synthetic lot fixtures explicitly consent to donation-style saves.
    // Tests can send donation_confirmed=0 to exercise the refusal path.
    if (str_starts_with($url,'/admin/kavel.php') && ($data['action']??'')==='save'
        && !array_key_exists('donation_confirmed',$data)) {
      $data['donation_confirmed']='1';
    }
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
  claim(money('850.50') === '€ 850,50'
      && money('1234.56') === '€ 1.234,56'
      && money('100') === '€ 100,00',
    'Public price formatting always shows exactly two decimal digits');
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

  // Private seller CRUD with fake contacts and IBAN, before any lots are created.
  [$sellerStatus, $sellerPage] = request_page('/admin/verkopers.php');
  claim($sellerStatus === 200 && str_contains($sellerPage, 'Verkopers beheren')
      && !str_contains($sellerPage, 'name="bank_account_iban"')
      && !str_contains($sellerPage, 'id="seller-form"'), 'Seller overview has no embedded form');
  [$sellerStatus, $newSellerPage] = request_page('/admin/verkoper.php');
  claim($sellerStatus===200 && str_contains($newSellerPage, 'name="bank_account_iban"')
      && str_contains($newSellerPage,'Terug naar verkopers'), 'New seller opens dedicated page');
  claim(str_contains($newSellerPage,'name="email" type="email"')
      && str_contains($newSellerPage,'id="seller-artist-fields"'),
      'Seller form offers required details and conditional artist section');
  $sellerCsrf=token($newSellerPage);
  [$sellerRequiredStatus, $missingSellerPage] = request_page('/admin/verkoper.php', [
    'csrf'=>$sellerCsrf,'action'=>'save','seller_type'=>'particulier','name'=>'Incomplete'
  ]);
  claim($sellerRequiredStatus===200 && str_contains($missingSellerPage,'E-mail is verplicht.')
    && (int)db()->query("SELECT COUNT(*) FROM lot_sellers WHERE name='Incomplete'")->fetchColumn()===0,
    'Server rejects incomplete new seller without writing a row');
  [$sellerStatus] = request_page('/admin/verkoper.php', [
    'csrf'=>$sellerCsrf,'action'=>'save','seller_type'=>'kunstenaar',
    'name'=>'Demo kunstenaar','email'=>'demo@example.invalid','street'=>'Fictieve straat',
    'house_number'=>'10','postal_code'=>'9160','municipality'=>'Lokeren',
    'bank_account_name'=>'Demo kunstenaar','bank_account_iban'=>'BE68 5390 0754 7034',
    'artist_name'=>'Demo Art','artist_url'=>'www.example.org',
    'artist_intro'=>'Fictieve korte voorstelling'
  ]);
  $sellerId=(int)db()->query("SELECT id FROM lot_sellers WHERE name='Demo kunstenaar'")->fetchColumn();
  claim($sellerStatus === 303 && $sellerId>0, 'Administrator creates artist seller');
  $sellerRecord=db()->query("SELECT * FROM lot_sellers WHERE id=$sellerId")->fetch();
  claim($sellerRecord['bank_account_iban']==='BE68539007547034'
    && $sellerRecord['artist_url']==='https://www.example.org',
    'Seller IBAN and artist URL normalized on save');
  $creatorActor=(int)db()->query("SELECT id FROM admins WHERE email='ci-admin@example.invalid'")->fetchColumn();
  claim((int)$sellerRecord['created_by_admin_id']===$creatorActor
    && (int)$sellerRecord['updated_by_admin_id']===$creatorActor
    && $sellerRecord['created_by_name']!=='' && $sellerRecord['updated_by_name']!==''
    && $sellerRecord['created_at']!==null && $sellerRecord['updated_at']!==null,
    'Original creator and last updater recorded on first save');
  [$sellerStatus, $sellerEditPage] = request_page('/admin/verkoper.php?id='.$sellerId);
  claim($sellerStatus===200 && str_contains($sellerEditPage,'value="Demo kunstenaar"'),
    'Seller form preloads existing data');
  claim(str_contains($sellerEditPage,'name="artist_intro"')
    && str_contains($sellerEditPage,'name="photo_portrait_1"'),
    'Artist editor exposes biography and optional portrait field');
  // A save that changes no canonical seller field must preserve last-update audit.
  db()->prepare("UPDATE lot_sellers SET updated_at='2020-01-01 00:00:00',updated_by_name='Earlier editor' WHERE id=?")->execute([$sellerId]);
  [$noChangeStatus] = request_page('/admin/verkoper.php?id='.$sellerId, [
    'csrf'=>token($sellerEditPage),'action'=>'save','seller_id'=>(string)$sellerId,
    'seller_type'=>'kunstenaar','name'=>'Demo kunstenaar','email'=>'demo@example.invalid',
    'street'=>'Fictieve straat','house_number'=>'10','postal_code'=>'9160','municipality'=>'Lokeren',
    'bank_account_name'=>'Demo kunstenaar','bank_account_iban'=>'BE68539007547034',
    'artist_name'=>'Demo Art','artist_url'=>'https://www.example.org',
    'artist_intro'=>'Fictieve korte voorstelling',
  ]);
  $unchangedAudit=db()->query("SELECT updated_at,updated_by_name FROM lot_sellers WHERE id=$sellerId")->fetch();
  claim($noChangeStatus===303 && $unchangedAudit['updated_at']==='2020-01-01 00:00:00'
    && $unchangedAudit['updated_by_name']==='Earlier editor',
    'Saving unchanged seller does not create an audit update');
  $artistFixture=$fixtures.'/artist-work.png';
  $artistImage=imagecreatetruecolor(64,64);
  imagefill($artistImage,0,0,imagecolorallocate($artistImage,20,100,80));
  imagepng($artistImage,$artistFixture);
  imagedestroy($artistImage);
  [$sellerStatus] = request_page('/admin/verkoper.php?id='.$sellerId, [
    'csrf'=>token($sellerEditPage),'action'=>'save','seller_id'=>(string)$sellerId,
    'seller_type'=>'kunstenaar','name'=>'Demo kunstenaar','email'=>'demo@example.invalid',
    'street'=>'Fictieve straat','house_number'=>'10','postal_code'=>'9160','municipality'=>'Lokeren',
    'artist_name'=>'Demo Art','artist_url'=>'www.example.org',
    'artist_intro'=>'Fictieve korte voorstelling',
    'photo_work_1'=>new CURLFile($artistFixture,'image/png','fictief-werk.png')
  ],true);
  $imagePath=(string)db()->query('SELECT path FROM seller_images WHERE seller_id='.$sellerId." AND image_kind='work' AND slot=1")->fetchColumn();
  claim($sellerStatus===303 && $imagePath!=='' && is_file(dirname(__DIR__).'/catalogus-public/'.$imagePath)
    && db()->query('SELECT artist_intro FROM lot_sellers WHERE id='.$sellerId)->fetchColumn()==='Fictieve korte voorstelling',
    'Artist introduction and first work photo saved with seller');
  [$sellerStatus] = request_page('/admin/verkoper.php?id='.$sellerId, [
    'csrf'=>token($sellerEditPage),'action'=>'save','seller_id'=>(string)$sellerId,
    'seller_type'=>'particulier','name'=>'Demo particulier','email'=>'nieuw@example.invalid',
    'street'=>'Fictieve straat','house_number'=>'10','postal_code'=>'9160','municipality'=>'Lokeren',
    'bank_account_name'=>'Demo particulier','bank_account_iban'=>'BE68 5390 0754 7034'
  ]);
  $sellerRecord=db()->query("SELECT * FROM lot_sellers WHERE id=$sellerId")->fetch();
  claim($sellerStatus===303 && $sellerRecord['seller_type']==='particulier'
    && $sellerRecord['artist_name']==='' && $sellerRecord['artist_url']===''
    && $sellerRecord['artist_intro']===''
    && (int)db()->query('SELECT COUNT(*) FROM seller_images WHERE seller_id='.$sellerId)->fetchColumn()===0,
    'Seller can change type and private artist fields are cleared');
  claim((int)$sellerRecord['created_by_admin_id']===$creatorActor
    && (int)$sellerRecord['updated_by_admin_id']===$creatorActor,
    'Editing keeps original creator and updates last editor');
  [$sellerStatus, $badSellerPage] = request_page('/admin/verkoper.php?id='.$sellerId, [
    'csrf'=>$sellerCsrf,'action'=>'save','seller_id'=>(string)$sellerId,
    'seller_type'=>'particulier','name'=>'Demo particulier',
    'email'=>'bad-email','bank_account_iban'=>'BE68 5390 0754 7035'
  ]);
  claim($sellerStatus===200 && str_contains($badSellerPage,'geldig e-mailadres')
    && db()->query("SELECT name FROM lot_sellers WHERE id=$sellerId")->fetchColumn()==='Demo particulier',
    'Invalid seller fields rejected without mutation');
  [$sellerStatus] = request_page('/admin/verkopers.php', [
    'csrf'=>$sellerCsrf,'action'=>'delete','seller_id'=>(string)$sellerId
  ]);
  claim($sellerStatus===303
    && (int)db()->query("SELECT COUNT(*) FROM lot_sellers WHERE id=$sellerId")->fetchColumn()===0,
    'Unused seller can be removed');
  [$sellerStatus] = request_page('/admin/verkoper.php', [
    'csrf'=>$sellerCsrf,'action'=>'save','seller_type'=>'particulier',
    'name'=>'Kavelverkoper Demo','email'=>'kavel@example.invalid',
    'street'=>'Voorbeeldstraat','house_number'=>'5','postal_code'=>'9160','municipality'=>'Lokeren',
    'bank_account_name'=>'Kavelverkoper Demo','bank_account_iban'=>'BE68 5390 0754 7034'
  ]);
  $sellerLinkId=(int)db()->query("SELECT id FROM lot_sellers WHERE name='Kavelverkoper Demo'")->fetchColumn();
  claim($sellerStatus===303 && $sellerLinkId>0, 'Private seller available for lot association');
  [$status, $lotsNav] = request_page('/admin/index.php');
  claim($status===200 && str_contains($lotsNav,'>Verkopers</a>'),
    'Administrator navigation exposes seller management');

  [$status, $html] = request_page('/admin/kavel.php');
  claim($status === 200 && str_contains($html,'lot-editor-form'), 'Authenticated kavel editor reachable');
  claim(str_contains($html, 'name="title"') && str_contains($html, 'maxlength="50"'),
      'New lot title input is limited to 50 characters');
  claim(str_contains($html,'name="donation_confirmed" value="0"')
    && str_contains($html,'geen uitbetaling gebeuren naar de schenker'),
    'New lot exposes explicit donation confirmation on save');
  [$donationStatus, $donationPage] = request_page('/admin/kavel.php', [
    'csrf'=>token($html),'action'=>'save','id'=>'0',
    'lot_number'=>'876541','title'=>'Schenking zonder verkoper','seller_id'=>'',
    'donation_confirmed'=>'0'
  ]);
  claim($donationStatus===200
    && str_contains($donationPage,'Bevestig dit om op te slaan')
    && !by_number(876541), 'Server rejects unconfirmed donation with no seller');
  [$titleStatus, $tooLongTitlePage] = request_page('/admin/kavel.php', [
    'csrf' => token($html), 'action' => 'save',
    'lot_number' => '876542', 'title' => str_repeat('X', 51),
  ]);
  claim($titleStatus === 200 && str_contains($tooLongTitlePage, 'maximaal 50 tekens')
      && !by_number(876542),
      'Server rejects a 51-character title without inserting a lot');
  $newPhotoPos = strpos($html, 'id="lot-new-photo-grid"');
  $newBottomPos = strpos($html, 'class="lot-editor-bottom"');
  claim($newPhotoPos !== false && $newBottomPos !== false && $newBottomPos > $newPhotoPos
      && str_contains($html, 'class="secondary lot-editor-cancel"')
      && substr_count($html, 'button form="lot-editor-form" class="primary" type="submit">Kavel opslaan</button>') === 2
      && !str_contains($html, 'Wijzigingen opslaan →'),
      'New lot displays top cancel and both Kavel opslaan actions; bottom actions follow photos');

  $publicationPos = strpos($html, 'class="lot-editor-card lot-editor-publication"');
  $layoutPos = strpos($html, 'class="lot-editor-layout lot-editor-layout--single"');
  $basicPos = strpos($html, '<h2>Basisinformatie</h2>');
  claim($publicationPos !== false && $layoutPos !== false && $basicPos !== false
      && $publicationPos < $basicPos && $layoutPos < $basicPos && str_contains($html,'<h2>Publicatie</h2>'),
      'Administrator sees publication even while creating a lot');

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

  claim(str_contains($html,'<h2>Publicatie</h2>') && str_contains($html, '<select name="category">')
      && str_contains($html,'>Moderne Kunst</option>') && str_contains($html,'>Oude Meesters</option>')
      && str_contains($html,'<select name="status">')
      && str_contains($html,'<select name="sale_location">'),
      'Administrator can choose publication status and sale location for a new lot');
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
    'category' => 'Moderne Kunst', 'description' => 'Niet opgeslagen',
    'market_price' => '-1', 'starting_bid' => '100', 'status' => 'published',
  ];
  [$status, $html] = request_page('/admin/kavel.php', $invalid);
  claim($status === 200 && str_contains($html,'Ongeldige marktprijs.'), 'Invalid market price rejected');
  claim(str_contains($html, 'value="Nieuwe titel &lt;&amp;&gt;"'), 'Submitted title restored and HTML escaped');
  claim(str_contains($html, 'name="market_price" value="-1"') && str_contains($html,'name="starting_bid" value="100"'), 'Submitted market price and starting bid restored');
  claim(lot_count() === 0, 'No new lot after invalid valuation');

  db()->prepare("INSERT INTO lots (lot_number,title,description,details,status) VALUES (10,'Originele titel','','','draft')")->execute();
  $id = (int)by_number(10)['id'];

  // Isolated category CRUD: roles, rename propagation and deletion while in use.
  [$categoryStatus, $categoryPage] = request_page('/admin/categorieen.php');
  claim($categoryStatus === 200 && str_contains($categoryPage, 'Moderne Kunst')
      && str_contains($categoryPage, 'Oude Meesters'),
      'Administrator can manage the initial categories');
  $categoryCsrf = token($categoryPage);
  [$categoryStatus] = request_page('/admin/categorieen.php', [
      'csrf'=>$categoryCsrf, 'action'=>'add', 'name'=>'Tijdelijke categorie']);
  claim($categoryStatus === 303
      && (int)db()->query("SELECT COUNT(*) FROM lot_categories WHERE name='Tijdelijke categorie'")->fetchColumn() === 1,
      'Administrator adds a category');
  $tempId = (int)db()->query("SELECT id FROM lot_categories WHERE name='Tijdelijke categorie'")->fetchColumn();
  [$categoryStatus] = request_page('/admin/categorieen.php', [
      'csrf'=>$categoryCsrf, 'action'=>'rename', 'category_id'=>(string)$tempId, 'name'=>'Tijdelijke naam']);
  claim($categoryStatus === 303
      && (int)db()->query("SELECT COUNT(*) FROM lot_categories WHERE name='Tijdelijke naam'")->fetchColumn() === 1,
      'Administrator renames an unused category');
  db()->prepare('UPDATE lots SET category=? WHERE id=?')->execute(['Tijdelijke naam',$id]);
  [$categoryStatus, $categoryPage] = request_page('/admin/categorieen.php', [
      'csrf'=>$categoryCsrf, 'action'=>'delete', 'category_id'=>(string)$tempId]);
  claim($categoryStatus === 200 && str_contains($categoryPage, 'Verwijderen kan alleen')
      && str_contains($categoryPage, '1 kavel(s)')
      && str_contains($categoryPage, 'disabled title="Categorie is in gebruik"')
      && (int)db()->query("SELECT COUNT(*) FROM lot_categories WHERE id=$tempId")->fetchColumn() === 1,
      'Usage count is correct and deletion is refused while a lot uses the category');
  [$categoryStatus] = request_page('/admin/categorieen.php', [
      'csrf'=>$categoryCsrf, 'action'=>'rename', 'category_id'=>(string)$tempId, 'name'=>'Categorie hernoemd']);
  claim($categoryStatus === 303 && by_number(10)['category'] === 'Categorie hernoemd',
      'Renaming a category propagates to linked lots');
  db()->prepare('UPDATE lots SET category=? WHERE id=?')->execute(['',$id]);
  [$categoryStatus] = request_page('/admin/categorieen.php', [
      'csrf'=>$categoryCsrf, 'action'=>'delete', 'category_id'=>(string)$tempId]);
  claim($categoryStatus === 303
      && (int)db()->query("SELECT COUNT(*) FROM lot_categories WHERE id=$tempId")->fetchColumn() === 0,
      'Unused category can be deleted');
  $invalid['id'] = (string)$id; $invalid['lot_number'] = '10'; $invalid['title']='Gewijzigde maar niet opgeslagen titel';
  [$status, $existingLayout] = request_page('/admin/kavel.php?id=' . $id);
  claim(!str_contains($existingLayout, '<h2>Kavelinformatie</h2>')
      && !str_contains($existingLayout, 'class="lot-editor-side"')
      && str_contains($existingLayout, 'class="lot-editor-layout lot-editor-layout--single"'),
    'Existing lot has no redundant information card or unused side column');

  claim($status === 200
      && str_contains($existingLayout, 'class="lot-editor-card lot-editor-publication"')
      && strpos($existingLayout, '<h2>Publicatie</h2>') < strpos($existingLayout, '<h2>Basisinformatie</h2>')
      && substr_count($existingLayout, '<h2>Publicatie</h2>') === 1,
      'Existing lot publication appears once before basic information');
  $editPhotoPos = strpos($existingLayout, 'id="lot-photo-grid"');
  $editActionsPos = strrpos($existingLayout, 'class="lot-editor-bottom"');
  claim($status === 200 && $editPhotoPos !== false && $editActionsPos !== false
      && $editActionsPos > $editPhotoPos
      && substr_count($existingLayout, '>Wijzigingen opslaan</button>') === 2
      && str_contains($existingLayout, 'class="secondary lot-editor-cancel"')
      && str_contains($existingLayout, 'button form="lot-editor-form" class="primary" type="submit">Wijzigingen opslaan</button>'),
    'Edit lot top cancel and save label, bottom actions below photo gallery');
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
    'title' => 'Upload te groot', 'category' => 'Moderne Kunst',
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
  $small['seller_id']=(string)$sellerLinkId;
  $small['photos[0]'] = new CURLFile($fixtures . '/valid.png', 'image/png', 'valid.png');
  [$status] = request_page('/admin/kavel.php', $small, true);
  claim($status === 303 && (bool)by_number(20), 'Valid kavel and photo saved');
  claim((int)by_number(20)['seller_id']===$sellerLinkId, 'New lot is linked to its private seller');
  [$status, $lotsWithSeller] = request_page('/admin/index.php');
  claim($status===200 && str_contains($lotsWithSeller,'<th scope="col">Verkoper</th>')
    && str_contains($lotsWithSeller,'class="dashboard-seller">Kavelverkoper Demo</td>'),
    'Admin lots overview displays linked seller name');
  [$sellerStatus, $sellerBlocked] = request_page('/admin/verkopers.php', [
    'csrf'=>$sellerCsrf,'action'=>'delete','seller_id'=>(string)$sellerLinkId
  ]);
  claim($sellerStatus===200 && str_contains($sellerBlocked, 'Verwijder eerst de koppeling')
    && (int)db()->query("SELECT COUNT(*) FROM lot_sellers WHERE id=$sellerLinkId")->fetchColumn()===1,
    'Cannot delete a seller used by an existing lot');
  $st = db()->prepare('SELECT path FROM lot_images WHERE lot_id=?');
  $st->execute([(int)by_number(20)['id']]);
  $imagePath = (string)$st->fetchColumn();
  claim($imagePath !== '' && is_file(dirname(__DIR__) . '/catalogus-public/' . $imagePath), 'Photo DB and file linked');
  // Regression: adding photos to an EXISTING lot through the real HTTP endpoint,
  // then saving the lot's other fields, must preserve the DB rows and physical files.
  $existingLotId = (int)by_number(20)['id'];
  $storedPhotoPaths = [$imagePath];
  [$status, $existingEditor] = request_page('/admin/kavel.php?id='.$existingLotId);
  claim($status === 200 && str_contains($existingEditor, 'lot-photo-upload-form'),
    'Existing lot editor exposes the independent photo upload form');
  for ($expectedCount = 2; $expectedCount <= 3; ++$expectedCount) {
    [$status, $json] = request_page('/admin/kavel.php?id='.$existingLotId, [
      'csrf' => token($existingEditor), 'action' => 'add-photo', 'id' => (string)$existingLotId,
      'photos[0]' => new CURLFile($fixtures . '/valid.png', 'image/png', 'extra-'.$expectedCount.'.png'),
    ], true, ['Accept: application/json']);
    $payload = json_decode($json, true);
    claim($status === 200 && is_array($payload) && ($payload['ok'] ?? false) === true
        && (int)($payload['count'] ?? 0) === $expectedCount
        && (int)($payload['photo_id'] ?? 0) > 0,
      'Existing lot accepts photo '.$expectedCount.' and confirms persisted count');
    $photoRows = db()->prepare('SELECT path FROM lot_images WHERE lot_id=? ORDER BY id');
    $photoRows->execute([$existingLotId]);
    $storedPhotoPaths = $photoRows->fetchAll(PDO::FETCH_COLUMN);
    claim(count($storedPhotoPaths) === $expectedCount
        && count(array_filter($storedPhotoPaths, static fn($path) =>
          is_file(dirname(__DIR__) . '/catalogus-public/' . $path))) === $expectedCount,
      'Existing lot image rows and files persist after photo '.$expectedCount);
    [$status, $existingEditor] = request_page('/admin/kavel.php?id='.$existingLotId);
    claim($status === 200 && substr_count($existingEditor, 'alt="Kavelfoto"') === $expectedCount,
      'Existing lot renders all saved photos after reopening');
  }
  [$status, $limitJson] = request_page('/admin/kavel.php?id='.$existingLotId, [
    'csrf' => token($existingEditor), 'action' => 'add-photo', 'id' => (string)$existingLotId,
    'photos[0]' => new CURLFile($fixtures . '/valid.png', 'image/png', 'fourth.png'),
  ], true, ['Accept: application/json']);
  $limitResult = json_decode($limitJson, true);
  claim($status === 422 && ($limitResult['ok'] ?? null) === false
      && str_contains((string)($limitResult['error'] ?? ''), 'Maximaal 3'),
    'Existing lot refuses a fourth photo via the actual upload endpoint');
  [$status] = request_page('/admin/kavel.php?id='.$existingLotId, [
    'csrf' => token($existingEditor), 'action' => 'save', 'id' => (string)$existingLotId,
    'lot_number' => '20', 'title' => 'Kavel bijgewerkt na foto-upload',
    'category' => 'Moderne Kunst', 'sale_location' => 'markt', 'status' => 'draft',
  ]);
  claim($status === 303 && by_number(20)['title'] === 'Kavel bijgewerkt na foto-upload',
    'Saving existing lot data succeeds after photo uploads');
  $photoRows = db()->prepare('SELECT path FROM lot_images WHERE lot_id=? ORDER BY id');
  $photoRows->execute([$existingLotId]);
  $savedAfterEdit = $photoRows->fetchAll(PDO::FETCH_COLUMN);
  [$status, $reopened] = request_page('/admin/kavel.php?id='.$existingLotId);
  claim($status === 200 && $savedAfterEdit === $storedPhotoPaths
      && substr_count($reopened, 'alt="Kavelfoto"') === 3
      && count(array_filter($savedAfterEdit, static fn($path) =>
        is_file(dirname(__DIR__) . '/catalogus-public/' . $path))) === 3,
    'All three photos survive lot save and editor reopen');
  // Only remove image files created by this disposable localhost test.
  foreach ($storedPhotoPaths as $storedPhotoPath) {
    @unlink(dirname(__DIR__) . '/catalogus-public/' . $storedPhotoPath);
  }

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
  claim($status === 200 && !str_contains($form, 'name="sale_location"'), 'New lot has fixed sale location');
  [$status] = request_page('/admin/kavel.php', [
    'csrf'=>token($form), 'action'=>'save', 'lot_number'=>'301',
    'title'=>'Auction one', 'sale_location'=>'veiling'
  ]);
  claim($status===303 && by_number(301)['sale_location']==='markt' && by_number(301)['auction_number']===null,
    'New lot is always created on Markt even if sale location is forged');
  [$status, $form] = request_page('/admin/kavel.php?id='.(int)by_number(301)['id']);
  [$status] = request_page('/admin/kavel.php?id='.(int)by_number(301)['id'], [
    'csrf'=>token($form), 'action'=>'save', 'id'=>(string)by_number(301)['id'],
    'lot_number'=>'301', 'title'=>'Auction one', 'sale_location'=>'veiling'
  ]);
  claim($status===303 && (int)by_number(301)['auction_number']===1,
    'First auction assignment after creation starts at 1');
  [$status, $form] = request_page('/admin/kavel.php');
  [$status] = request_page('/admin/kavel.php', [
    'csrf'=>token($form), 'action'=>'save', 'lot_number'=>'302',
    'title'=>'Auction two', 'sale_location'=>'veiling'
  ]);
  claim($status===303 && by_number(302)['auction_number']===null,
    'Second lot also starts on Markt');
  [$status, $form] = request_page('/admin/kavel.php?id='.(int)by_number(302)['id']);
  [$status] = request_page('/admin/kavel.php?id='.(int)by_number(302)['id'], [
    'csrf'=>token($form), 'action'=>'save', 'id'=>(string)by_number(302)['id'],
    'lot_number'=>'302', 'title'=>'Auction two', 'sale_location'=>'veiling'
  ]);
  claim($status===303 && (int)by_number(302)['auction_number']===2,
    'Second auction number increments after assignment');
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
    str_contains($body, 'id="lot-cancel-warning"') && str_contains($body, 'Annuleren niet mogelijk') &&
    by_number(301)['status']==='sold' && by_number(301)['title']==='Auction one',
    'Auction lot cannot be canceled');
  [$httpStatus, $editor] = request_page('/admin/kavel.php?id='.$auctionId);
  [$httpStatus, $body] = request_page('/admin/kavel.php?id='.$auctionId, [
    'csrf'=>token($editor), 'action'=>'save', 'id'=>(string)$auctionId,
    'lot_number'=>'301', 'title'=>'Blocked combined cancel', 'sale_location'=>'markt', 'status'=>'canceled'
  ]);
  claim($httpStatus===200 && str_contains($body,'eerst afzonderlijk over naar Markt') &&
    str_contains($body, 'id="lot-cancel-warning"') && str_contains($body, 'Annuleren niet mogelijk') &&
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
  [$sellerOverviewStatus, $sellerAssistantOverview] = request_page('/admin/verkopers.php');
  claim($sellerOverviewStatus===200 && str_contains($sellerAssistantOverview,'Verkopers beheren'),
    'Assistant may open seller management');
  [$sellerFormStatus, $sellerAssistantForm] = request_page('/admin/verkoper.php');
  claim($sellerFormStatus===200 && str_contains($sellerAssistantForm,'Nieuwe verkoper'),
    'Assistant may open new seller editor');
  [$sellerFormStatus] = request_page('/admin/verkoper.php', [
    'csrf'=>token($sellerAssistantForm),'action'=>'save','seller_type'=>'particulier',
    'name'=>'Fictieve assistentverkoper','email'=>'assistent@example.invalid',
    'street'=>'Voorbeeldstraat','house_number'=>'7','postal_code'=>'9160','municipality'=>'Lokeren'
  ]);
  $assistantSeller=db()->query("SELECT created_by_admin_id,updated_by_admin_id,created_by_name,updated_by_name,created_at,updated_at FROM lot_sellers WHERE name='Fictieve assistentverkoper'")->fetch();
  claim($sellerFormStatus===303 && $assistantSeller
    && (int)$assistantSeller['created_by_admin_id']===$actor
    && (int)$assistantSeller['updated_by_admin_id']===$actor
    && $assistantSeller['created_at']!==null && $assistantSeller['updated_at']!==null
    && $assistantSeller['created_by_name']!=='' && $assistantSeller['updated_by_name']!=='',
    'Assistant creates seller with authenticated creator and edit timestamps');
  db()->prepare("UPDATE admins SET role='veilingmeester' WHERE id=?")->execute([$actor]);
  [$masterListStatus, $masterListPage] = request_page('/admin/verkopers.php');
  claim($masterListStatus===200 && str_contains($masterListPage,'Aangemaakt door / op'),
    'Auctioneer can view seller overview with audit columns');
  $assistantSellerId=(int)db()->query("SELECT id FROM lot_sellers WHERE name='Fictieve assistentverkoper'")->fetchColumn();
  [$masterEditStatus, $masterEditor] = request_page('/admin/verkoper.php?id='.$assistantSellerId);
  claim($masterEditStatus===200 && str_contains($masterEditor,'Laatste wijziging:'),
    'Auctioneer can open existing seller and see last editor');
  [$masterSaveStatus] = request_page('/admin/verkoper.php?id='.$assistantSellerId, [
    'csrf'=>token($masterEditor),'action'=>'save','seller_id'=>(string)$assistantSellerId,
    'seller_type'=>'particulier','name'=>'Fictieve verkoper gewijzigd',
    'email'=>'assistent@example.invalid','street'=>'Voorbeeldstraat',
    'house_number'=>'7','postal_code'=>'9160','municipality'=>'Lokeren',
    'created_by_admin_id'=>'999999','updated_by_admin_id'=>'999999',
    'created_by_name'=>'FAKE','updated_by_name'=>'FAKE'
  ]);
  $masterEditedSeller=db()->query("SELECT name,created_by_admin_id,updated_by_admin_id,created_by_name,updated_by_name,updated_at FROM lot_sellers WHERE id=".$assistantSellerId)->fetch();
  claim($masterSaveStatus===303 && $masterEditedSeller['name']==='Fictieve verkoper gewijzigd'
    && (int)$masterEditedSeller['created_by_admin_id']===$actor
    && (int)$masterEditedSeller['updated_by_admin_id']===$actor
    && $masterEditedSeller['created_by_name']===$assistantSeller['created_by_name']
    && $masterEditedSeller['updated_by_name']===$assistantSeller['updated_by_name']
    && $masterEditedSeller['updated_by_name']!=='FAKE'
    && $masterEditedSeller['updated_at']!==null,
    'Auctioneer edits seller and forged audit actor fields are ignored');
  db()->prepare("UPDATE admins SET role='assistant' WHERE id=?")->execute([$actor]);
  [$status, $ownForm] = request_page('/admin/kavel.php?id='.(int)by_number(401)['id']);
  claim($status===200 && !str_contains($ownForm,'alleen-lezen')
    && !str_contains($ownForm,'<h2>Publicatie</h2>'), 'Assistant may edit own draft without publication box');
  [$status, $assistantNewForm] = request_page('/admin/kavel.php');
  claim($status===200 && !str_contains($assistantNewForm,'<h2>Publicatie</h2>'),
    'Assistant cannot see publication when creating a lot');
  $csrfAssistant = token($ownForm);
  [$status] = request_page('/admin/kavel.php?id='.(int)by_number(401)['id'], [
    'csrf'=>$csrfAssistant,'action'=>'save','id'=>(string)by_number(401)['id'],
    'lot_number'=>'401','title'=>'Own edited','status'=>'published','sale_location'=>'veiling'
  ]);
  claim($status===303 && by_number(401)['title']==='Own edited'
    && by_number(401)['status']==='draft' && by_number(401)['sale_location']==='markt',
    'Assistant edits own draft but cannot publish or change sale location by forged POST');
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

  // A lot now stores only the seller foreign key; private bank data is not duplicated.
  [$status, $newSellerForm] = request_page('/admin/verkoper.php');
  $sellerCreate=[
    'csrf'=>token($newSellerForm),'action'=>'save','seller_type'=>'kunstenaar',
    'name'=>'Fictieve maker','email'=>'maker@example.invalid','street'=>'Teststraat',
    'house_number'=>'7','postal_code'=>'9160','municipality'=>'Lokeren',
    'bank_account_name'=>'Fictieve maker','bank_account_iban'=>'BE68 5390 0754 7034',
    'artist_name'=>'Demo artiest','artist_url'=>'https://example.org/artist'
  ];
  [$status] = request_page('/admin/verkoper.php',$sellerCreate);
  $artistSellerId=(int)db()->query("SELECT id FROM lot_sellers WHERE name='Fictieve maker'")->fetchColumn();
  claim($status===303 && $artistSellerId>0,'Artist seller created in private seller registry');
  [$status, $artistForm] = request_page('/admin/kavel.php');
  claim($status===200 && str_contains($artistForm,'name="seller_id"')
    && str_contains($artistForm,'+ Nieuwe verkoper')
    && !str_contains($artistForm,'name="artist_name"')
    && !str_contains($artistForm,'name="bank_account_iban"'),
    'Lot form uses seller selector and private seller creation link only');
  $artistPost=[
    'csrf'=>token($artistForm),'action'=>'save','id'=>'0',
    'lot_number'=>'750000','title'=>'Kunstwerk met maker',
    'seller_id'=>(string)$artistSellerId,'status'=>'draft','sale_location'=>'markt'
  ];
  [$status] = request_page('/admin/kavel.php',$artistPost);
  $artistStored=by_number(750000);
  claim($status===303 && $artistStored && (int)$artistStored['seller_id']===$artistSellerId,
    'Lot persists only its referenced seller ID');
  [$status, $artistEditor] = request_page('/admin/kavel.php?id='.$artistStored['id']);
  claim($status===200 && str_contains($artistEditor,'value="'.$artistSellerId.'" selected'),
    'Existing lot reload selects the linked seller');
  [$status] = request_page('/admin/kavel.php?id='.$artistStored['id'], [
    'csrf'=>token($artistEditor),'action'=>'save','id'=>(string)$artistStored['id'],
    'lot_number'=>'750000','title'=>'Updated seller lot','seller_id'=>'999999',
    'status'=>'draft','sale_location'=>'markt'
  ]);
  claim($status===200 && (int)by_number(750000)['seller_id']===$artistSellerId,
    'Forged seller ID rejected without changing lot');
  [$status] = request_page('/admin/kavel.php?id='.$artistStored['id'], [
    'csrf'=>token($artistEditor),'action'=>'save','id'=>(string)$artistStored['id'],
    'lot_number'=>'750000','title'=>'Updated seller lot','seller_id'=>(string)$artistSellerId,
    'status'=>'draft','sale_location'=>'markt'
  ]);
  claim($status===303 && by_number(750000)['title']==='Updated seller lot'
    && (int)by_number(750000)['seller_id']===$artistSellerId,
    'Unrelated lot edit preserves linked seller');
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
