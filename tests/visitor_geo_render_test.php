<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/visitor_geo_render.php';
$html = visitor_geo_render_percentage_table([
 ['label'=>'BE','percentage'=>65.5],
 ['label'=>'<unsafe>','percentage'=>34.5],
], 'Landen');
if (!str_contains($html, '65,5%') || !str_contains($html, '&lt;unsafe&gt;')
    || str_contains($html, '<unsafe>') || str_contains($html, 'visitor_days')) {
    throw new RuntimeException('Percentage-only rendering or escaping failed');
}
if (!str_contains(visitor_geo_render_percentage_table([], 'Steden'), 'Nog geen geografische gegevens')) {
    throw new RuntimeException('Empty geo state absent');
}
try {
    visitor_geo_render_percentage_table([['label'=>'BE','percentage'=>50.0,'visitor_days'=>10]], 'Landen');
    throw new RuntimeException('Absolute geo values unexpectedly accepted');
} catch (InvalidArgumentException $expected) {}
echo "Geographic percentage-only HTML rendering OK\n";
foreach ([NAN, INF, -INF, '50', -1.0, 101.0] as $invalid) {
    try {
        visitor_geo_render_percentage_table([['label'=>'BE','percentage'=>$invalid]], 'Landen');
        throw new RuntimeException('Invalid percentage accepted');
    } catch (InvalidArgumentException $expected) {}
}
