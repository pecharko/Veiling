<?php
declare(strict_types=1);

/** Only delete a regular, non-symlinked photo under the actual uploads directory. */
function catalog_safe_unlink_photo(string $path, string $publicRoot): bool {
    if (!preg_match('~\\Auploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.(?:webp|jpg|png)\\z~D', $path)) return false;
    $root = realpath(rtrim($publicRoot, '/').'/uploads');
    if ($root === false || !is_dir($root) || is_link(rtrim($publicRoot, '/').'/uploads')) return false;
    $parts = explode('/', $path);
    $year = $root.'/'.$parts[1];
    $month = $year.'/'.$parts[2];
    if (is_link($year) || is_link($month) || !is_dir($year) || !is_dir($month)) return false;
    if (realpath($year) !== $year || realpath($month) !== $month) return false;
    $file = $month.'/'.$parts[3];
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file) || realpath($file) !== $file) return false;
    return @unlink($file);
}
