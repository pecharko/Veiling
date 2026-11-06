<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$script=file_get_contents($root.'/scripts/preview');
$visual=file_get_contents($root.'/dev/preview/visual_test.cjs');
$doc=file_get_contents($root.'/docs/local-ui-preview.md');
if (!is_string($script) || !is_string($visual) || !is_string($doc)) throw new RuntimeException('Missing browser preview files');
foreach ([
    'PREVIEW_BROWSER_EXECUTABLE',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    'No installed Chrome/Chromium browser found on macOS',
    'if [ "$(uname -s)" = Darwin ]',
] as $s) if (!str_contains($script,$s)) throw new RuntimeException('Missing Mac browser fallback: '.$s);
if (!str_contains($visual,'executablePath: browserPath')) throw new RuntimeException('Browser fallback not used by Playwright');
if (!str_contains($doc,'geen automatische Chromium-download')) throw new RuntimeException('Missing recovery documentation');
echo "PASS macOS preview browser fallback contract\n";
