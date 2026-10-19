<?php
declare(strict_types=1);

/** Offline regression: a photo-less lot must have a tracked and safe image fallback. */
$root = dirname(__DIR__);
$placeholder = $root . '/catalogus-public/assets/placeholder.svg';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(is_file($placeholder), 'Missing catalogus-public/assets/placeholder.svg.');
$svg = (string)file_get_contents($placeholder);
$assert(strlen($svg) > 100 && strlen($svg) < 20000, 'Unexpected placeholder size.');
$assert(str_contains($svg, '<svg ') && str_contains($svg, '</svg>'), 'Placeholder is not an SVG.');
$assert(!preg_match('~<(?:script|foreignObject|iframe|image)\\b|\\bon[a-z]+\\s*=|(?:https?://|data:|javascript:)~i', str_replace('http://www.w3.org/2000/svg', '', $svg)), 'Unsafe or remote SVG content.');

foreach (['index.php', 'kavel.php'] as $page) {
    $php = (string)file_get_contents($root . '/catalogus-public/' . $page);
    $assert(str_contains($php, 'assets/placeholder.svg'), "{$page} does not use the photo placeholder.");
}
echo "Public catalogue placeholder: 6 safety and reference checks passed\n";
