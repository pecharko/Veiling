<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_once dirname(__DIR__, 2) . '/private/lot_sellers.php';
require_auth();

// Read-only profile for authenticated staff. Editing and all mutation endpoints
// retain their existing, stricter seller_require_edit authorization.
if (!seller_can_manage(effective_admin_role())) {
  http_response_code(403);
  exit('Geen toegang.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  header('Allow: GET');
  exit('Alleen bekijken.');
}
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$type = (string)($_GET['type'] ?? '');
if ($id === false || $id === null || !in_array($type, ['kunstenaar', 'particulier'], true)) {
  http_response_code(404);
  exit('Profiel niet gevonden.');
}
$pdo = db();
$stmt = $pdo->prepare('SELECT id,seller_type,name,artist_name,artist_url,artist_slogan,artist_intro,artist_published FROM lot_sellers WHERE id=? AND seller_type=?');
$stmt->execute([(int)$id, $type]);
$seller = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$seller) {
  http_response_code(404);
  exit('Profiel niet gevonden.');
}
$isArtist = $type === 'kunstenaar';
$backUrl = url($isArtist ? 'admin/kunstenaars.php' : 'admin/verkopers.php');
$displayName = $isArtist ? (string)$seller['artist_name'] : (string)$seller['name'];
$photoRows = [];
if ($isArtist) {
  $photos = $pdo->prepare("SELECT image_kind,path FROM seller_images WHERE seller_id=? AND image_kind IN ('portrait','work') ORDER BY image_kind,slot");
  $photos->execute([(int)$id]);
  $photoRows = $photos->fetchAll(PDO::FETCH_ASSOC);
}
admin_top($isArtist ? 'Kunstenaar bekijken' : 'Verkoper bekijken');
?>
<div class="seller-manager seller-editor-page">
  <div class="seller-editor-toolbar">
    <div>
      <a class="lot-editor-back" href="<?=e($backUrl)?>">← <?=$isArtist ? 'Terug naar kunstenaars' : 'Terug naar verkopers'?></a>
      <p class="dashboard-subtitle">Alleen bekijken · bewerken is niet toegestaan voor dit profiel.</p>
    </div>
  </div>
  <section class="lot-editor-card seller-editor-card">
    <div class="lot-editor-card-header"><div>
      <h2><?=e($displayName)?></h2>
      <p><?=$isArtist ? 'Kunstenaar' : 'Particuliere verkoper'?></p>
    </div></div>
    <?php if ($isArtist): ?>
      <div class="seller-fields-section">
        <h3>Kunstenaarprofiel</h3>
        <p><strong>Status:</strong> <?=((int)$seller['artist_published'] === 1 ? 'Gepubliceerd' : ((int)$seller['artist_published'] === 2 ? 'Nieuw' : 'Niet gepubliceerd'))?></p>
        <?php if ((string)$seller['artist_slogan'] !== ''): ?><p><strong>Slogan:</strong> <?=e((string)$seller['artist_slogan'])?></p><?php endif; ?>
        <?php if ((string)$seller['artist_intro'] !== ''): ?><p><?=nl2br(e((string)$seller['artist_intro']))?></p><?php endif; ?>
        <?php $website = (string)$seller['artist_url']; ?>
        <?php if (preg_match('~^https?://~i', $website)): ?><p><a href="<?=e($website)?>" target="_blank" rel="noopener noreferrer">Website kunstenaar</a></p><?php endif; ?>
      </div>
      <?php if ($photoRows): ?>
        <div class="seller-fields-section">
          <h3>Foto’s</h3>
          <div class="lot-editor-photo-grid seller-lot-photo-grid">
            <?php foreach ($photoRows as $photo): ?>
              <?php if (!seller_artist_has_portrait_path((string)$photo['path'])) continue; ?>
              <div class="lot-editor-photo"><img src="<?=e(url((string)$photo['path']))?>" alt="<?=$photo['image_kind']==='portrait'?'Portret van kunstenaar':'Foto van werk'?>" loading="lazy"></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="seller-fields-section"><h3>Verkoper</h3><p><?=e($displayName)?></p></div>
    <?php endif; ?>
    <p class="lot-editor-help">Persoonlijke contact- en bankgegevens zijn niet beschikbaar in deze alleen-lezenweergave.</p>
  </section>
</div>
<?php admin_bottom(); ?>
