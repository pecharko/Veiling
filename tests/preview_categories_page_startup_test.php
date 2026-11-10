<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$script=file_get_contents($root.'/scripts/preview');
$page=file_get_contents($root.'/catalogus-public/admin/categorieen.php');
if (!is_string($script)||!is_string($page)) throw new RuntimeException('Preview files missing');
$start=strpos($script,"  start)");
$end=$start===false?false:strpos($script,"  test)",$start);
if ($start===false || $end===false) throw new RuntimeException('Missing start command');
$section=substr($script,$start,$end-$start);
if (!str_contains($section,'dc up -d --build --wait')
    || !str_contains($section,'dc exec -T preview-web php dev/preview/seed.php')
    || !str_contains($section,'diagnose_preview')) throw new RuntimeException('Preview start must upgrade and verify isolated data');
if (str_contains($section,'dc down') || str_contains($section,'--volumes')) throw new RuntimeException('Preview start must not remove volumes');
if (!str_contains($page,'catch (PDOException $ex)') || !str_contains($page,"http_response_code(503);")) throw new RuntimeException('Category GET must fail clearly if schema unavailable');
if (!str_contains($page,"if (!lot_category_can_manage(effective_admin_role()))")) throw new RuntimeException('Role guard lost');
echo "PASS categories preview startup and missing-schema diagnostic\n";
