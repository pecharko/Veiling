<?php
declare(strict_types=1);

/** Operational metadata only. File is in private/ and never exposed over HTTP. */
function visitor_stats_cron_log_path(): string
{
    return __DIR__ . '/visitor_stats_cron_log.json';
}

function visitor_stats_cron_log_read(): array
{
    $path = visitor_stats_cron_log_path();
    if (!is_file($path)) return [];
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) > 32768) return [];
    $decoded = json_decode($data, true);
    if (!is_array($decoded) || !array_is_list($decoded)) return [];
    return array_slice(array_values(array_filter($decoded, static function ($row): bool {
        return is_array($row)
            && is_string($row['finished_utc'] ?? null)
            && in_array($row['status'] ?? null, ['ok', 'error', 'limit'], true)
            && is_int($row['rows_deleted'] ?? null);
    })), 0, 30);
}

function visitor_stats_cron_log_append(array $entry): bool
{
    $path = visitor_stats_cron_log_path();
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) return false;
    try {
        @chmod($path . '.lock', 0600);
        if (!flock($lock, LOCK_EX)) return false;
        $entries = visitor_stats_cron_log_read();
        array_unshift($entries, $entry);
        $json = json_encode(array_slice($entries, 0, 30), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $tmp = @tempnam(__DIR__, '.cronlog-');
        if ($tmp === false) return false;
        try {
            @chmod($tmp, 0600);
            if (@file_put_contents($tmp, $json . "\n") === false) return false;
            return @rename($tmp, $path);
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
