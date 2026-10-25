<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
$admin = file_get_contents($root . '/catalogus-public/admin/instellingen.php');
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
if ($home === false || $admin === false || $detail === false) { throw new RuntimeException('Missing PHP source'); }
foreach ([
  'if ($published): ?><a href="#catalogus">Bekijk kavels',
  'if ($published): ?><a id="favoriteLink"',
  'if ($published): ?><a class="front-btn front-btn-primary"',
  'if ($published): ?>',
  'if ($published) {',
] as $guard) {
  if (!str_contains($home, $guard)) throw new RuntimeException('Missing public lot visibility guard: ' . $guard);
}
if (!str_contains($admin, 'Kavels tonen op website') || !str_contains($admin, "is_published")) {
  throw new RuntimeException('Global admin visibility toggle changed');
}
if (!str_contains($detail, "http_response_code(404)") || !str_contains($detail, "is_published")) {
  throw new RuntimeException('Direct lot URL access gate missing');
}
echo "PASS global lot visibility contract\n";
