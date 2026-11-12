<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$list=file_get_contents($root.'/catalogus-public/admin/verkopers.php');
$editor=file_get_contents($root.'/catalogus-public/admin/verkoper.php');
$css=file_get_contents($root.'/catalogus-public/admin/admin.css');
if (!is_string($list) || !is_string($editor) || !is_string($css)) throw new RuntimeException('Missing seller sources.');
foreach (['Aangemaakt door / op','Laatst gewijzigd door / op','class="seller-audit"'] as $removed) {
  if (str_contains($list,$removed)) throw new RuntimeException('Audit column still visible in sellers overview: '.$removed);
}
foreach (['<th scope="col">Naam</th>','<th scope="col">Acties</th>','colspan="5"'] as $required) {
  if (!str_contains($list,$required)) throw new RuntimeException('Seller overview layout missing '.$required);
}
foreach (['id="seller-manager-form"','id="seller-save-button"','id="seller-save-progress"','id="seller-upload-progress"','id="seller-save-error"','xhr.upload.addEventListener(\'progress\'','xhr.upload.addEventListener(\'load\'','xhr.setRequestHeader(\'X-Requested-With\'','xhr.responseType=\'json\'','xhr.status===422','saving=true','submit.disabled=true','window.location.assign(target.href)','role="status"'] as $required) {
  if (!str_contains($editor,$required)) throw new RuntimeException('Seller upload indicator missing '.$required);
}
foreach (['$ajaxSave=is_post()','$ajaxReply(200','$ajaxReply(422','check_csrf()','seller_save($pdo,$editId,$_POST,$actorId,$actorRole)'] as $required) {
  if (!str_contains($editor,$required)) throw new RuntimeException('Seller save behavior missing '.$required);
}
if (!str_contains($editor,'class="seller-audit-summary"') || strpos($editor,'class="seller-audit-summary"')<strpos($editor,'class="seller-photo-delete-grid"')) throw new RuntimeException('Seller audit must remain at page bottom.');
if (!str_contains($css,'.seller-save-progress[hidden]')) throw new RuntimeException('Missing upload styling.');
echo "PASS seller list omits audit columns, editor tracks upload and keeps bottom audit.\n";
