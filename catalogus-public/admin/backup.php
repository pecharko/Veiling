<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_auth();
require dirname(__DIR__,2).'/private/snapshots.php';
require_once dirname(__DIR__,2).'/private/catalog_reset.php';
require_no_index();
$error='';$info='';
if(($_GET['download']??'')!==''){
 $name=(string)$_GET['download'];
 try{
  $file=snap_path($name);if(!is_file($file))throw new RuntimeException('Snapshot niet gevonden.');
  header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.filesize($file));
  header('Cache-Control: no-store, private');readfile($file);exit;
 }catch(Throwable $e){http_response_code(404);exit('Snapshot niet gevonden.');}
}
if(is_post()){
 check_csrf();$action=(string)($_POST['action']??'');
 try{
  if($action==='create'){$name=snap_create(db());flash('Snapshot aangemaakt: '.$name);redirect('admin/backup.php');}
  elseif($action==='restore'){
   $name=(string)($_POST['snapshot']??'');
   if(($_POST['confirm']??'')!=='HERSTEL')throw new RuntimeException('Typ HERSTEL om terugzetten te bevestigen.');
   // Keep one MySQL advisory lock across BOTH steps: no concurrent admin
   // write can slip between the safety snapshot and the actual restore.
   // Nested locks inside snap_create/snap_restore are balanced on this PDO.
   $pdo=db();
   catalog_data_lock($pdo);
   try{
    $safety=snap_create($pdo);
    snap_restore($pdo,$name);
   }finally{catalog_data_unlock($pdo);}
   admin_session();$_SESSION=[];session_regenerate_id(true);
   flash('Snapshot hersteld. Meld opnieuw aan. Voor de herstelactie is een veiligheidskopie gemaakt: '.$safety);
   redirect('admin/login.php');
  }
  elseif($action==='reset-lots'){
   require_administrator();
   if(($_POST['confirm']??'')!=='WIS ALLE KAVELS EN HERSTART OP 1') throw new RuntimeException('Typ de volledige bevestigingszin om alle kavels te verwijderen.');
   // No snapshot is created automatically: this reset deliberately removes the
   // issued-number registry. Existing snapshot ZIP archives remain untouched.
   $pdo=db(); catalog_data_lock($pdo);
   try{
    $result=reset_catalog_lots($pdo);
    // Keep the mutex during photo cleanup: another editor must not write
    // while files from the deleted catalog are being removed.
    $failed=reset_catalog_photo_files($result['paths'], dirname(__DIR__));
   }finally{catalog_data_unlock($pdo);}
   flash($result['deleted'].' kavels verwijderd. Volgend kavelnummer is 1.'.($failed?' Waarschuwing: '.$failed.' fotobestanden konden niet worden verwijderd.':''),$failed?'warning':'success');
   redirect('admin/backup.php');
  }
  elseif($action==='delete'){
   $name=(string)($_POST['snapshot']??'');if(($_POST['confirm']??'')!=='VERWIJDER')throw new RuntimeException('Typ VERWIJDER om te bevestigen.');
   snap_init();$lock=snap_lock();try{$p=snap_path($name);if(!is_file($p)||!unlink($p))throw new RuntimeException('Kon snapshot niet verwijderen.');}finally{flock($lock,LOCK_UN);fclose($lock);}
   flash('Snapshot verwijderd.');redirect('admin/backup.php');
  }else throw new RuntimeException('Onbekende actie.');
 }catch(Throwable $e){error_log('[41Lokeren backup] '.$e->getMessage());$error=$e->getMessage();}
}
$available=snap_listing();$total=array_sum(array_column($available,'size'));
$quota=1073741824; // OVHcloud Starter: 1 GiB nominale webopslag; pas aan als abonnement wijzigt.
$storage=snap_storage_stats($quota);
$level=snap_storage_level((float)$storage['percent']);
admin_top('Back-up & herstel');
?>
<style>
.storage-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:15px}.storage-stat{border:1px solid #eadbd0;background:#fffaf6;border-radius:12px;padding:14px}.storage-stat strong{font-size:22px;display:block;color:#850015}.storage-stat span{font-size:12px;color:#68746f}.storage-meter{height:15px;border-radius:20px;background:#f0e5df;overflow:hidden}.storage-meter div{height:100%;background:#5b9680;border-radius:20px}.storage-meter.warning div{background:#d79528}.storage-meter.critical div{background:#b32436}.storage-rows{display:grid;grid-template-columns:1fr auto;gap:7px 12px;font-size:14px;margin-top:17px}.storage-callout{margin:12px 0;border-radius:10px;padding:13px 15px;background:#fff5df;border:1px solid #edcc8d;color:#765016}.storage-callout.critical{background:#fff0f0;border-color:#e7abb4;color:#9b1c2e}.storage-note{font-size:12px;color:#68746f;margin-top:15px}@media(max-width:650px){.storage-grid{grid-template-columns:1fr}}.backup-intro{color:#62716d;max-width:75ch}.backup-layout{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(280px,.9fr);gap:22px;align-items:start}.backup-card{background:#fff;border:1px solid #eadbd0;border-radius:16px;padding:25px;margin-bottom:22px;box-shadow:0 7px 24px rgba(78,32,35,.04)}.backup-card h2{margin:0 0 10px;font-size:22px}.backup-card p{color:#62716d}.backup-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.backup-list{width:100%;border-collapse:collapse}.backup-list td,.backup-list th{padding:12px 8px;text-align:left;border-bottom:1px solid #eee4df}.backup-list th{font-size:12px;color:#6a7771}.backup-list td{font-size:13px}.backup-list a{font-weight:700}.backup-warning{padding:15px;border-radius:10px;background:#fff4e7;border:1px solid #f4d29a;color:#78511c}.backup-confirm{width:100%;max-width:410px;padding:11px 12px;margin:7px 0 12px}.backup-select{width:100%;max-width:100%;padding:11px 12px;margin:8px 0 12px}.backup-subtle{font-size:13px;color:#738078}.backup-danger{background:#a32031;color:#fff;border:0;padding:12px 18px;border-radius:8px;cursor:pointer}.backup-error{background:#fff0f0;color:#9b1c2e;padding:14px;border-radius:9px;margin-bottom:18px}.backup-sep{border:0;border-top:1px solid #eee4df;margin:24px 0}@media(max-width:850px){.backup-layout{grid-template-columns:1fr}.backup-card{padding:18px}.backup-list{display:block;overflow-x:auto}}
</style>
<p class="backup-intro">Bewaar een herstelpunt van de volledige catalogus: kavels, veilinginformatie, beheerders en kavelfoto’s. Maak na grote wijzigingen aan kavels of foto's een snapshot en download die ook naar je Mac.</p>
<?php if($error):?><div class="backup-error" role="alert"><?=e($error)?></div><?php endif;?>
<section class="backup-card" aria-label="Hostingopslag"><h2>Opslagoverzicht</h2>
<div class="storage-grid"><div class="storage-stat"><span>Hostinggebruik (geschat)</span><strong><?=e(snap_human_size($storage['total']))?></strong></div><div class="storage-stat"><span>Geschatte resterende ruimte</span><strong><?=e(snap_human_size($storage['free']))?></strong></div><div class="storage-stat"><span>Snapshots (<?=count($available)?>)</span><strong><?=e(snap_human_size($storage['snapshots']))?></strong></div></div>
<div class="storage-meter <?=e($level)?>" role="progressbar" aria-label="Geschat opslaggebruik" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?=e((string)min(100,round($storage['percent'])))?>"><div style="width:<?=e((string)min(100,round($storage['percent'],1)))?>%"></div></div>
<p class="backup-subtle" style="margin:9px 0 0"><?=e(number_format($storage['percent'],1,',','.'))?>% van <?=e(snap_human_size($storage['quota']))?> nominale hostinglimiet</p>
<?php if($level!=='ok'):?><div class="storage-callout <?=$level==='critical'?'critical':''?>" role="alert"><strong><?=$level==='critical'?'Kritiek: opslag bijna vol':'Waarschuwing: opslag loopt op'?></strong><div>Je gebruikt naar schatting <?=e(number_format($storage['percent'],1,',','.'))?>% van je hostingruimte. Download oude snapshots naar je Mac en verwijder ze daarna van de server.</div></div><?php endif;?>
<div class="storage-rows"><span>Kavelfoto's (onderdeel van de website)</span><strong><?=e(snap_human_size($storage['uploads']))?></strong><span>Snapshots (onderdeel van private/)</span><strong><?=e(snap_human_size($storage['snapshots']))?></strong><span>Publieke website, inclusief foto's</span><strong><?=e(snap_human_size($storage['parts']['catalogus-public']))?></strong><span>Private bestanden, inclusief snapshots</span><strong><?=e(snap_human_size($storage['parts']['private']))?></strong><span>Oude www-map</span><strong><?=e(snap_human_size($storage['parts']['www']))?></strong><span>Overige bestanden</span><strong><?=e(snap_human_size($storage['parts']['other']))?></strong></div>
<p class="storage-note">Dit is een bestandsmeting van de hoofdmap van deze hosting, geen officiële quota-meting van OVHcloud. De onderdelen “foto's” en “snapshots” zijn al inbegrepen in de respectieve mappen hierboven. Bestanden buiten de toegankelijke hoofdmap, verborgen providergebruik en eventuele andere quota tellen mogelijk niet mee. Controleer de officiële teller in OVHcloud Manager.</p>
<?php foreach($storage['warnings'] as $warning):?><div class="storage-callout" role="alert"><?=e($warning)?></div><?php endforeach;?></section>
<div class="backup-layout">
<div>
<section class="backup-card"><h2>Nieuw herstelpunt</h2><p>De snapshot wordt opgeslagen buiten de publieke website, in <code>private/backups</code>. Download daarna een tweede kopie naar je computer.</p><p class="backup-subtle">Beperking: maximaal 350 MB foto's per snapshot. Er is extra vrije schijfruimte nodig. Dit is geen back-up van de PHP-programmabestanden.</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create"><button type="submit" class="primary">+ Snapshot maken</button></form></section>
<section class="backup-card"><h2>Bewaarde snapshots</h2><p><?=count($available)?> snapshots · <?=number_format($total/1024/1024,1,',','.')?> MB opgeslagen op de hosting.</p>
<?php if(!$available):?><p>Nog geen herstelpunten aangemaakt.</p><?php else:?><div style="overflow-x:auto"><table class="backup-list"><thead><tr><th>Snapshot</th><th>Grootte</th><th>Actie</th></tr></thead><tbody><?php foreach($available as $item):?><tr><td><strong><?=e(date('d/m/Y H:i',$item['time']))?></strong><div class="backup-subtle"><?=e($item['name'])?></div></td><td><?=e(number_format($item['size']/1024/1024,1,',','.'))?> MB</td><td><a href="<?=e(url('admin/backup.php',['download'=>$item['name']]))?>">Download ↓</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
</div>
<div>
<section class="backup-card"><h2>Snapshot herstellen</h2><div class="backup-warning"><strong>Let op:</strong> herstel vervangt de huidige database en alle kavelfoto’s door de gekozen snapshot. Ook beheerdersaccounts en wachtwoordhashes worden hersteld.</div><p style="margin-top:15px">Vóór het herstellen maakt de applicatie automatisch een veiligheidskopie van de huidige toestand. Als die kopie niet lukt, start het herstel niet.</p>
<form method="post" onsubmit="return confirm('Weet je zeker dat je de VOLLEDIGE catalogus wilt terugzetten naar deze snapshot?');"><?=csrf_field()?><input type="hidden" name="action" value="restore"><label for="restore-snapshot">Kies het herstelpunt</label><select required class="backup-select" name="snapshot" id="restore-snapshot"><option value="">Selecteer snapshot</option><?php foreach($available as $item):?><option value="<?=e($item['name'])?>"><?=e(date('d/m/Y H:i',$item['time']))?> (<?=e(number_format($item['size']/1024/1024,1,',','.'))?> MB)</option><?php endforeach;?></select><label for="restore-confirm">Typ HERSTEL om te bevestigen</label><input class="backup-confirm" name="confirm" id="restore-confirm" required autocomplete="off" placeholder="HERSTEL"><div><button type="submit" class="backup-danger" <?=!$available?'disabled':''?>>Geselecteerde snapshot herstellen</button></div></form></section>
<section class="backup-card"><h2>Opslag beheren</h2><p>Je hosting heeft beperkte opslag. Verwijder oude herstelpunten pas nadat je ze lokaal hebt gedownload en gecontroleerd.</p>
<form method="post" onsubmit="return confirm('Deze snapshot definitief van de server verwijderen?');"><?=csrf_field()?><input type="hidden" name="action" value="delete"><label for="delete-snapshot">Snapshot verwijderen</label><select required class="backup-select" name="snapshot" id="delete-snapshot"><option value="">Selecteer snapshot</option><?php foreach($available as $item):?><option value="<?=e($item['name'])?>"><?=e($item['name'])?></option><?php endforeach;?></select><label for="delete-confirm">Typ VERWIJDER</label><input class="backup-confirm" name="confirm" id="delete-confirm" required autocomplete="off" placeholder="VERWIJDER"><div><button type="submit" class="secondary" <?=!$available?'disabled':''?>>Snapshot van server wissen</button></div></form>
<hr class="backup-sep"><p class="backup-subtle">Een gedownloade ZIP kun je later via RemoteDrive/SFTP uploaden naar <code>private/backups/</code>. Daarna verschijnt die in de lijst en kun je hem herstellen. Bewaar ZIP-bestanden zorgvuldig: ze bevatten ook beheerdersgegevens.</p></section>
</div></div>
<?php if(effective_admin_role()==='administrator'):?>
<section class="backup-card" aria-label="Volledige kavelreset">
<h2>Alle kavels verwijderen en nummering herstarten</h2>
<div class="backup-warning"><strong>Onomkeerbare beheeractie.</strong> Verwijdert alle kavels en hun fotoreferenties en wist alle uitgegeven gewone kavelnummers, zodat het volgende nieuwe kavel nummer 1 krijgt. Veilingsnummers beginnen eveneens opnieuw bij 1. Bestaande snapshot-ZIP's en beheerdersaccounts blijven bestaan. Herstel van een oude snapshot kan kavels terugbrengen; voer geen reset uit tijdens andere catalogusbewerkingen.</div>
<form method="post" onsubmit="return confirm('ALLE kavels en alle nummerreservaties definitief wissen? Dit kan niet ongedaan worden gemaakt zonder afzonderlijk herstel.');">
<?=csrf_field()?><input type="hidden" name="action" value="reset-lots">
<label for="reset-lots-confirm">Typ WIS ALLE KAVELS EN HERSTART OP 1</label>
<input class="backup-confirm" name="confirm" id="reset-lots-confirm" required autocomplete="off" placeholder="WIS ALLE KAVELS EN HERSTART OP 1">
<button type="submit" class="backup-danger">Alle kavels verwijderen en herstarten</button>
</form>
</section>
<?php endif;?>
<?php admin_bottom(); ?>
