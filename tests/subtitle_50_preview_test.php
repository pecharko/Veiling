<?php
declare(strict_types=1);
// Offline contract: keep the persisted subtitle field while limiting new edits.
$editor = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/kavel.php');
if (!is_string($editor)) throw new RuntimeException('Editor not readable');
function subtitle_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}
subtitle_check(str_contains($editor, '<label class="lot-full">Ondertitel<input type="text" name="subtitle"') && str_contains($editor, 'name="subtitle" value="<?=e($d[\'subtitle\'])?>" maxlength="50"'), 'Subtitle label and enforced 50-character HTML limit');
subtitle_check(!str_contains($editor, 'Ondertitel / maker / materiaal'), 'Outdated label removed');
subtitle_check(str_contains($editor, 'id="lot-subtitle-error"'), 'Accessible inline length warning');
subtitle_check(str_contains($editor, "const subtitleMessage = 'De ondertitel mag maximaal 50 tekens bevatten.'"), 'Exact message');
subtitle_check(str_contains($editor, 'Array.from(subtitle.value.trim()).length > 50'), 'Character count in editor');
subtitle_check(str_contains($editor, 'subtitle.setCustomValidity(tooLong ? subtitleMessage :'), 'Save blocked by browser validation');
subtitle_check(str_contains($editor, "elseif(mb_strlen(\$subtitle)>50)\$error='De ondertitel mag maximaal 50 tekens bevatten.';"), 'Server rejects long values before write');
subtitle_check(str_contains($editor, "UPDATE lots SET lot_number=?,title=?,subtitle=?"), 'Existing DB subtitle field in UPDATE');
subtitle_check(str_contains($editor, "INSERT INTO lots (lot_number,title,subtitle,"), 'Existing DB subtitle field in INSERT');
subtitle_check(!str_contains($editor, "\$subtitle=mb_substr(trim((string)(\$_POST['subtitle']??'')),0,50)"), 'Long existing values are not silently truncated to 50');
echo "PASS: subtitle label, 50-character validation and unchanged database field.\n";
