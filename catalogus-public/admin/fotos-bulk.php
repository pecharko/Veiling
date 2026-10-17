<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require dirname(__DIR__, 2) . '/private/images.php';
require_auth();
$folder=dirname(__DIR__,2).'/private/photo-inbox';
if (!is_dir($folder)) @mkdir($folder,0750,true);
$files=array_values(array_filter(scandir($folder) ?: [], static fn($name)=>is_file($folder.'/'.$name) && preg_match('/\.(jpe?g|png|webp)$/i',$name)));
natcasesort($files);$files=array_values($files);
$processed=0;$skipped=0;$messages=[];
if(is_post()){
  check_csrf();
  $pdo=db();
  foreach(array_slice($files,0,20) as $file){
    if(!preg_match('/^0*(\d{1,6})[_-](\d{1,2})\.(jpe?g|png|webp)$/i',$file,$m)){
      $skipped++;$messages[]=$file.' — gebruik 001_01.jpg';continue;
    }
    $nr=(int)$m[1];$order=(int)$m[2];
    $st=$pdo->prepare('SELECT id FROM lots WHERE lot_number=?');$st->execute([$nr]);$lotId=$st->fetchColumn();
    if(!$lotId){$skipped++;$messages[]=$file.' — lot '.$nr.' bestaat niet';continue;}
    $already=$pdo->prepare('SELECT id FROM lot_images WHERE lot_id=? AND source_name=?');$already->execute([(int)$lotId,$file]);
    if($already->fetchColumn()){$skipped++;$messages[]=$file.' — eerder verwerkt';continue;}
    try{
      add_lot_image((int)$lotId,$folder.'/'.$file,$file,$order,$file);
      @unlink($folder.'/'.$file);
      $processed++;
    }catch(Throwable $ex){$skipped++;$messages[]=$file.' — '.$ex->getMessage();}
  }
  flash($processed.' foto’s verwerkt; '.$skipped.' overgeslagen. '.($messages?'Zie eventuele meldingen na een nieuwe poging.':''),$skipped?'warning':'success');
  if (!$messages) redirect('admin/fotos-bulk.php');
}
admin_top('Foto’s in bulk koppelen');
?><div class="panel form-panel"><p class="lead">Bij honderden kavels kun je alle foto's via SFTP in één beveiligde map plaatsen. Het lotnummer in de bestandsnaam bepaalt automatisch bij welk kavel de foto hoort.</p><div class="image-workflow"><div><strong>1. Benoem je foto's</strong><code>001_01.jpg<br>001_02.jpg<br>002_01.webp<br>150_01.jpg</code><small>Het getal vóór het streepje is het lotnummer; erna de volgorde van de foto's.</small></div><div><strong>2. Upload met SFTP</strong><code>private/photo-inbox/</code><small>De map staat bewust buiten de openbare website. Gebruik de FTP/SFTP-toegang van OVHcloud.</small></div><div><strong>3. Verwerk de map</strong><small>De website controleert en optimaliseert de afbeeldingen, koppelt ze aan de kavels en verwijdert met succes verwerkte originelen uit de importmap.</small></div></div><div class="import-status"><strong><?=count($files)?></strong> fotobestanden klaar voor verwerking</div><form method="post"><?=csrf_field()?><button class="primary" type="submit" <?=!$files?'disabled':''?>>Verwerk maximaal 20 foto's →</button></form><p class="hint">Herhaal indien nodig tot alle foto's verwerkt zijn. Foto's: JPG, PNG of WebP, maximaal 8 MB en 12 megapixels per bestand. Het systeem bewaart maximaal 16 foto's per kavel.</p><?php if($messages): ?><div class="alert warning"><strong>Niet verwerkte bestanden:</strong><ul><?php foreach($messages as $m): ?><li><?=e($m)?></li><?php endforeach; ?></ul></div><?php endif; ?></div>
<?php admin_bottom(); ?>
