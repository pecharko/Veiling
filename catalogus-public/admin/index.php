<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
$pdo = db();
$s = settings();
$total = (int)$pdo->query('SELECT COUNT(*) FROM lots')->fetchColumn();
$published = (int)$pdo->query("SELECT COUNT(*) FROM lots WHERE status='published'")->fetchColumn();
$withPhoto = (int)$pdo->query('SELECT COUNT(DISTINCT lot_id) FROM lot_images')->fetchColumn();
$q = mb_substr(trim((string)($_GET['q'] ?? '')),0,100);
$status = in_array($_GET['status'] ?? '', ['draft','published'], true) ? (string)$_GET['status'] : '';
$page = max(1,min(1000,(int)($_GET['pagina'] ?? 1)));
$perPage = 50;
$where = []; $p = [];
if ($q!=='') { $where[] = '(CAST(lot_number AS CHAR) LIKE ? OR title LIKE ? OR category LIKE ?)'; $p=array_fill(0,3,'%'.$q.'%'); }
if ($status!=='') { $where[]='status=?'; $p[]=$status; }
$w = $where ? 'WHERE '.implode(' AND ',$where) : '';
$count=$pdo->prepare('SELECT COUNT(*) FROM lots '.$w);$count->execute($p);
$filtered=(int)$count->fetchColumn();
$pages=max(1,(int)ceil($filtered/$perPage));$page=min($page,$pages);
$st=$pdo->prepare('SELECT l.*, (SELECT COUNT(*) FROM lot_images li WHERE li.lot_id=l.id) AS photo_count FROM lots l '.$w.' ORDER BY l.lot_number LIMIT ? OFFSET ?');
$i=1;foreach($p as $val)$st->bindValue($i++,$val);
$st->bindValue($i++,$perPage,PDO::PARAM_INT);$st->bindValue($i,($page-1)*$perPage,PDO::PARAM_INT);$st->execute();$rows=$st->fetchAll();
function dashboard_page_url(int $page): string { $p=$_GET;$p['pagina']=$page;return url('admin/index.php',$p); }
admin_top('Kaveloverzicht');
?><div class="stat-grid"><div class="stat"><small>TOTAAL AANGEMAAKT</small><strong><?=$total?></strong><span>kavels</span></div><div class="stat"><small>GEPUBLICEERD</small><strong><?=$published?></strong><span>kavels zichtbaar</span></div><div class="stat"><small>MET MINSTENS 1 FOTO</small><strong><?=$withPhoto?></strong><span>kavels</span></div><div class="stat"><small>WEBSITE</small><strong class="stat-small"><?=((int)$s['is_published']===1)?'Online':'Verborgen'?></strong><span><?=((int)$s['is_published']===1)?'catalogus staat aan':'nog niet publiek'?></span></div></div>
<div class="admin-actions"><a class="primary" href="<?=e(url('admin/kavel.php'))?>">+ Nieuw kavel</a><a class="secondary" href="<?=e(url('admin/fotos-bulk.php'))?>">▣ Foto's importeren</a><a class="secondary" href="<?=e(url('admin/instellingen.php'))?>">⚙ Veilinginformatie</a></div>
<section class="panel"><div class="panel-head"><div><h2>Alle kavels</h2><p><?=e((string)$filtered)?> resultaten. Ongepubliceerde kavels blijven onzichtbaar voor bezoekers.</p></div><form class="quick-filter" method="get"><input type="search" name="q" placeholder="Zoek kavel of titel…" value="<?=e($q)?>"><select name="status"><option value="">Alle statussen</option><option value="draft" <?=$status==='draft'?'selected':''?>>Concept</option><option value="published" <?=$status==='published'?'selected':''?>>Gepubliceerd</option></select><button class="secondary" type="submit">Zoeken</button></form></div><div class="table-wrap"><table><thead><tr><th>Nr.</th><th>Titel</th><th>Categorie</th><th>Foto's</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><strong><?=e(str_pad((string)$row['lot_number'],3,'0',STR_PAD_LEFT))?></strong></td><td><a href="<?=e(url('admin/kavel.php',['id'=>(int)$row['id']]))?>"><?=e($row['title'])?></a></td><td><?=e($row['category'])?></td><td><?=e((string)$row['photo_count'])?></td><td><span class="status <?=$row['status']==='published'?'live':'draft'?>"><?=$row['status']==='published'?'Gepubliceerd':'Concept'?></span></td><td><a href="<?=e(url('admin/kavel.php',['id'=>(int)$row['id']]))?>">Bewerken →</a></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="6">Nog geen kavels, of geen kavels voor deze zoekopdracht.</td></tr><?php endif; ?></tbody></table></div><?php if($pages>1): ?><div class="admin-pages"><?php if($page>1): ?><a href="<?=e(dashboard_page_url($page-1))?>">← Vorige</a><?php endif; ?><span>Pagina <?=$page?> / <?=$pages?></span><?php if($page<$pages): ?><a href="<?=e(dashboard_page_url($page+1))?>">Volgende →</a><?php endif; ?></div><?php endif; ?></section>
<?php admin_bottom(); ?>
