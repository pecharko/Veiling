<?php
declare(strict_types=1);

// Read-only regression contract: compact lot navigation only on mobile.
$root = dirname(__DIR__);
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
$css = file_get_contents($root . '/catalogus-public/assets/public-buttons.css');
if (!is_string($detail) || !is_string($css)) {
    throw new RuntimeException('Lot detail markup or public button CSS missing.');
}
foreach ([
    'detail-side-nav-prev' => ['Vorig kavel', '&lt;'],
    'detail-side-nav-next' => ['Volgend kavel', '&gt;'],
] as $class => [$name, $symbol]) {
    $start = strpos($detail, '<a class="' . $class . '"');
    $end = $start === false ? false : strpos($detail, '</a>', $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('Lot navigation link missing: ' . $class);
    }
    $link = substr($detail, $start, $end + 4 - $start);
    if (!str_contains($link, 'aria-label="' . $name . '"') ||
        !str_contains($link, '<span class="detail-side-nav-text">' . $name . '</span>') ||
        !str_contains($link, '<span class="detail-side-nav-symbol" aria-hidden="true">' . $symbol . '</span>')) {
        throw new RuntimeException('Missing mobile arrow or accessible full label for ' . $class);
    }
}
if (!str_contains($detail, "assets/public-buttons.css'))?>?v=4")) {
    throw new RuntimeException('Lot detail must load the fresh mobile-navigation CSS.');
}
foreach ([
    '.detail-side-nav-symbol {',
    'display: none;',
    '@media screen and (max-width: 767px)',
    '.detail-side-nav .detail-side-nav-text {',
    '.detail-side-nav .detail-side-nav-symbol {',
    'display: block;',
    'font-size: 26px;',
    'width: 44px;',
    'min-height: 48px;',
] as $rule) {
    if (!str_contains($css, $rule)) {
        throw new RuntimeException('Missing lot-navigation CSS requirement: ' . $rule);
    }
}
if (!str_contains($detail, "url('kavel.php',['nr'=>(int)\$prev])") ||
    !str_contains($detail, "url('kavel.php',['nr'=>(int)\$next])")) {
    throw new RuntimeException('Previous and next lot links must keep their original destinations.');
}
echo "PASS mobile lot navigation shows only < and >, desktop labels and accessible names remain.\n";
