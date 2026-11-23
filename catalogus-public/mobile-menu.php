<?php
if (!function_exists('url')) {
    http_response_code(404);
    exit;
}

$mobilePage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$showMobileCatalog = $mobilePage !== 'index.php' || ($published ?? false);
?>
<details class="public-mobile-menu">
  <summary>Menu</summary>
  <nav aria-label="Hoofdnavigatie">
    <?php if ($mobilePage !== 'index.php'): ?>
      <a href="<?=e(url('index.php'))?>">Home</a>
    <?php endif; ?>
    <?php if ($showMobileCatalog): ?>
      <a href="<?=e(url('index.php'))?>#catalogus">Bekijk kavels</a>
      <?php if ($mobilePage !== 'kunstenaars.php'): ?>
        <a href="<?=e(url('kunstenaars.php'))?>">Kunstenaars</a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($mobilePage !== 'goede-doel.php'): ?>
      <a href="<?=e(url('goede-doel.php'))?>">Het goede doel</a>
    <?php endif; ?>
    <?php if ($mobilePage !== 'vorige-editie.php'): ?>
      <a href="<?=e(url('vorige-editie.php'))?>">Vorige editie</a>
    <?php endif; ?>
  </nav>
</details>
