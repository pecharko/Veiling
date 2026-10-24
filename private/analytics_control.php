<?php
declare(strict_types=1);

// No schema migration or changes to the existing statistics are needed.
// The absence of this marker retains the current, enabled-by-default behavior.
function analytics_disabled_marker(): string {
    return __DIR__ . '/analytics.disabled';
}
function analytics_collection_enabled(): bool {
    return !is_file(analytics_disabled_marker());
}
function set_analytics_collection_enabled(bool $enabled): void {
    $marker = analytics_disabled_marker();
    if ($enabled) {
        if (is_file($marker) && !unlink($marker)) {
            throw new RuntimeException('Kon bezoekersregistratie niet inschakelen.');
        }
        if (is_file($marker)) throw new RuntimeException('Status kon niet worden bevestigd.');
    } else {
        if (!is_file($marker)) {
            $file = @fopen($marker, 'x'); // PHP x is exclusive, writable creation.
            if ($file === false) throw new RuntimeException('Kon bezoekersregistratie niet uitschakelen.');
            fclose($file);
        }
        if (!is_file($marker)) throw new RuntimeException('Status kon niet worden bevestigd.');
    }
}
