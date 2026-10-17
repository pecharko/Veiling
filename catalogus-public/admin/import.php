<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
$error='';
if (is_post()) {
  check_csrf();
  try {
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
      throw new RuntimeException('Selecteer een CSV-bestand.');
    }
    if ((int)$_FILES['csv']['size'] > 3 * 1024 * 1024) throw new RuntimeException('Het CSV-bestand mag maximaal 3 MB groot zijn.');
    $fh = fopen($_FILES['csv']['tmp_name'], 'rb');
    if (!$fh) throw new RuntimeException('CSV kon niet worden gelezen.');
    $first = fgets($fh);
    if ($first === false) throw new RuntimeException('CSV is leeg.');
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
    arsort($counts); $delimiter = (string)array_key_first($counts);
    rewind($fh);
    $head = fgetcsv($fh, 0, $delimiter, '"', '');
    if (!$head) throw new RuntimeException('CSV-kopregel ontbreekt.');
    $aliases = [
      'lotnummer'=>'lotnummer','nummer'=>'lotnummer','lot_number'=>'lotnummer',
      'titel'=>'titel','title'=>'titel',
      'categorie'=>'categorie','category'=>'categorie',
      'ondertitel'=>'ondertitel','subtitle'=>'ondertitel',
      'omschrijving'=>'omschrijving','beschrijving'=>'omschrijving','description'=>'omschrijving',
      'details'=>'details','afmetingen'=>'details',
      'schatting_van'=>'schatting_van','minimum'=>'schatting_van','estimate_min'=>'schatting_van',
      'schatting_tot'=>'schatting_tot','maximum'=>'schatting_tot','estimate_max'=>'schatting_tot',
      'publiceren'=>'publiceren','status'=>'publiceren'
    ];
    $columns = [];
    foreach ($head as $i=>$label) {
      $label = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$label)));
      if (isset($aliases[$label])) $columns[$aliases[$label]] = $i;
    }
    if (!isset($columns['lotnummer'], $columns['titel'])) throw new RuntimeException('Verplichte kolommen: lotnummer en titel.');
    $value = static function(array $row, string $col) use ($columns): string {
      return isset($columns[$col]) ? trim((string)($row[$columns[$col]] ?? '')) : '';
    };
    $number = static function(string $input): ?string {
      if ($input === '') return null;
      $n = preg_replace('/[\s\x{00A0}]+/u', '', $input);
      if (str_contains($n, ',')) $n = str_replace(['.', ','], ['', '.'], $n);
      if (!is_numeric($n) || (float)$n < 0 || (float)$n > 9999999999) throw new RuntimeException('Schatting heeft een ongeldig bedrag: ' . $input);
      return (string)$n;
    };
    $records=[]; $seen=[];
    $line=1;
    while (($row=fgetcsv($fh, 0, $delimiter, '"', ''))!==false) {
      $line++;
      if (count($row)===1 && trim((string)$row[0])==='') continue;
      if ($line>2001) throw new RuntimeException('Maximaal 2.000 kavels per CSV-import.');
      $rawNum=$value($row,'lotnummer');
      if (!ctype_digit($rawNum) || (int)$rawNum<1 || (int)$rawNum>999999) throw new RuntimeException('Ongeldig lotnummer op regel '.$line.'.');
      $nr=(int)$rawNum;
      if (isset($seen[$nr])) throw new RuntimeException('Dubbel lotnummer '. $nr .' in het CSV-bestand.');
      $seen[$nr]=true;
      $title=mb_substr($value($row,'titel'),0,250);
      if ($title==='') throw new RuntimeException('Titel ontbreekt op regel '.$line.'.');
      $min=$number($value($row,'schatting_van'));
      $max=$number($value($row,'schatting_tot'));
      if ($min!==null && $max!==null && (float)$max < (float)$min) throw new RuntimeException('Schatting is omgekeerd op regel '.$line.'.');
      $publish=mb_strtolower($value($row,'publiceren'));
      $status=in_array($publish,['ja','yes','1','true','published','gepubliceerd'],true)?'published':'draft';
      $records[]=[
        $nr,$title,mb_substr($value($row,'ondertitel'),0,250),
        mb_substr($value($row,'categorie')?:'Overige',0,120),
        mb_substr($value($row,'omschrijving'),0,30000),
        mb_substr($value($row,'details'),0,10000),$min,$max,$status
      ];
    }
    fclose($fh);
    if (!$records) throw new RuntimeException('Geen kavels gevonden in CSV.');
    $pdo=db();
    $known=array_fill_keys($pdo->query('SELECT lot_number FROM lots')->fetchAll(PDO::FETCH_COLUMN), true);
    $hasStatus=isset($columns['publiceren']);
    $sql='INSERT INTO lots (lot_number,title,subtitle,category,description,details,estimate_min,estimate_max,status) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),subtitle=VALUES(subtitle),category=VALUES(category),description=VALUES(description),details=VALUES(details),estimate_min=VALUES(estimate_min),estimate_max=VALUES(estimate_max)';
    if ($hasStatus) $sql .= ',status=VALUES(status)';
    $pdo->beginTransaction();
    try {
      $st=$pdo->prepare($sql);
      foreach($records as $record) $st->execute($record);
      $pdo->commit();
    } catch(Throwable $ex) { if($pdo->inTransaction())$pdo->rollBack(); throw $ex; }
    $created=0;$updated=0;
    foreach($records as $r){if(isset($known[$r[0]]))$updated++;else$created++;}
    flash($created.' nieuwe kavels, '.$updated.' bijgewerkte kavels. Foto’s blijven behouden. ' . ($hasStatus?'Statussen zijn uit de CSV overgenomen.':'Bestaande publicatiestatussen bleven behouden.'),'success');
    redirect('admin/index.php');
  } catch (Throwable $ex) {
    $error=$ex instanceof PDOException?'Kon CSV niet verwerken. Controleer het databaseformaat.':$ex->getMessage();
    if($ex instanceof PDOException)error_log($ex->getMessage());
  }
}
admin_top('CSV-import van kavels');
if($error)echo '<div class="alert error">'.e($error).'</div>';
?><div class="panel form-panel"><p class="lead">Importeer tot 2.000 kavels tegelijk. Bestaande lotnummers worden bijgewerkt; foto’s blijven aan het kavel gekoppeld. Controleer alles voor publicatie.</p><form method="post" enctype="multipart/form-data"><?=csrf_field()?><label>CSV-bestand uit Excel (UTF-8)<input type="file" name="csv" accept=".csv,text/csv,text/plain" required></label><p class="hint">Gebruik de kolommen <code>lotnummer; titel; categorie; ondertitel; omschrijving; details; schatting_van; schatting_tot; publiceren</code>.</p><p class="hint">Zet <strong>ja</strong> in ‘publiceren’ om een kavel publiek te maken, of <strong>nee</strong> voor een concept. Zonder kolom ‘publiceren’ blijven bestaande statussen behouden en worden nieuwe kavels als concept aangemaakt.</p><button type="submit" class="primary">CSV importeren →</button><a class="secondary" href="<?=e(url('assets/voorbeeld-kavels.csv'))?>" download>Download voorbeeld-CSV</a></form></div><div class="note"><strong>Tip voor meer dan 150 kavels</strong><p>Vul eerst alle kavels in één Excel-bestand in, exporteer naar CSV UTF-8 en importeer ze hier. Daarna kun je foto's per kavel toevoegen, of in één keer met de fotomap-import.</p></div>
<?php admin_bottom(); ?>
