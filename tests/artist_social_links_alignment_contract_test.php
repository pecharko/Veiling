<?php
declare(strict_types=1);
// Offline artist links contract: both optional external links have one accessible,
// responsive row and an equivalent SVG icon. No database or OVH access.
$root = dirname(__DIR__);
$page = file_get_contents($root.'/catalogus-public/kunstenaar.php');
$css = file_get_contents($root.'/catalogus-public/assets/catalogus.css');
if (!is_string($page) || !is_string($css)) throw new RuntimeException('Artist link source missing');
$checks = [
  'shared links container when either link exists' => "if (\$artistWebsite !== '' || \$artistInstagram !== '')",
  'accessible link group' => 'class="artist-brochure-links" aria-label="Externe links van de kunstenaar"',
  'website optional' => "if (\$artistWebsite !== '')",
  'Instagram optional' => "if (\$artistInstagram !== '')",
  'website shared link class' => 'class="artist-brochure-website"><a class="artist-brochure-link"',
  'Instagram shared link class' => 'class="artist-brochure-instagram"><a class="artist-brochure-link"',
  'website accessible label' => 'aria-label="Website van <?=e($name)?>"',
  'Instagram accessible label' => 'aria-label="Instagram-profiel van <?=e($name)?> (opent in een nieuw tabblad)"',
  'safe external URL escape for website' => 'href="<?=e($artistWebsite)?>"',
  'safe external URL escape for Instagram' => 'href="<?=e($artistInstagram)?>"',
  'website in the same tab' => 'href="<?=e($artistWebsite)?>" rel="noreferrer"',
  'Instagram retains its target' => 'href="<?=e($artistInstagram)?>" target="_blank" rel="noopener noreferrer"',
  'shared centered responsive row' => '.artist-brochure-links{margin-top:clamp(32px,5vw,64px);padding-top:20px;border-top:1px solid #d5c9be;display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap}',
  'shared icon sizing' => '.artist-brochure-links .artist-brochure-link svg{display:block;flex:none;width:24px;height:24px}',
  'small mobile width' => '@media(max-width:420px){.artist-brochure-links{gap:10px}',
  'CSS cache update' => "assets/catalogus.css'))?>?v=198",
];
foreach ($checks as $label => $needle) {
  if (!str_contains(($label==='shared centered responsive row'||$label==='shared icon sizing'||$label==='small mobile width') ? $css : $page, $needle)) {
    throw new RuntimeException('Artist external links regression: '.$label);
  }
}
if (substr_count($page, 'class="artist-brochure-link"') !== 2) {
  throw new RuntimeException('Both links must use the same accessible button styling');
}
if (substr_count($page, '<svg aria-hidden="true" viewBox="0 0 24 24" width="24" height="24"') !== 2) {
  throw new RuntimeException('Website and Instagram require equivalent icon SVGs');
}
if (!str_contains($page, '<circle cx="12" cy="12" r="9"/>') || !str_contains($page, '<rect x="2.5" y="2.5" width="19" height="19" rx="5"/>')) {
  throw new RuntimeException('Expected globe and Instagram symbols missing');
}
echo "PASS aligned and accessible artist website/Instagram icon links\\n";
