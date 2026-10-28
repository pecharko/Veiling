<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
require_once dirname(__DIR__, 2) . '/private/lot_permissions.php';
$pdo = db();
$s = settings();
$total = (int)$pdo->query('SELECT COUNT(*) FROM lots')->fetchColumn();
$published = (int)$pdo->query("SELECT COUNT(*) FROM lots WHERE status='published'")->fetchColumn();
$inAuction = (int)$pdo->query("SELECT COUNT(*) FROM lots WHERE sale_location='veiling'")->fetchColumn();
$inMarket = (int)$pdo->query("SELECT COUNT(*) FROM lots WHERE sale_location='markt'")->fetchColumn();
$withoutPhoto = (int)$pdo->query('SELECT COUNT(*) FROM lots l WHERE NOT EXISTS (SELECT 1 FROM lot_images li WHERE li.lot_id=l.id)')->fetchColumn();
$q = mb_substr(trim((string)($_GET['q'] ?? '')),0,100);
$category = mb_substr(trim((string)($_GET['category'] ?? '')),0,100);
$categories = $pdo->query("SELECT DISTINCT category FROM lots WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($category,$categories,true)) $category='';
$status = in_array($_GET['status'] ?? '', ['draft','published','sold','canceled'], true) ? (string)$_GET['status'] : '';
$saleLocation = in_array($_GET['sale_location'] ?? '', ['markt','veiling'], true) ? (string)$_GET['sale_location'] : '';
$photoFilter = ($_GET['photos'] ?? '') === 'none' ? 'none' : '';
$page = max(1,min(1000,(int)($_GET['pagina'] ?? 1)));
$perPage = 50;
$where = []; $p = [];
if ($q!=='') { $where[] = '(CAST(lot_number AS CHAR) LIKE ? OR title LIKE ? OR category LIKE ?)'; $p=array_fill(0,3,'%'.$q.'%'); }
if ($category!=='') { $where[]='category=?'; $p[]=$category; }
if ($status!=='') { $where[]='status=?'; $p[]=$status; }
if ($saleLocation!=='') { $where[]='sale_location=?'; $p[]=$saleLocation; }
if ($photoFilter==='none') { $where[]='NOT EXISTS (SELECT 1 FROM lot_images li WHERE li.lot_id=l.id)'; }
$w = $where ? 'WHERE '.implode(' AND ',$where) : '';
$count=$pdo->prepare('SELECT COUNT(*) FROM lots l '.$w);$count->execute($p);
$filtered=(int)$count->fetchColumn();
$pages=max(1,(int)ceil($filtered/$perPage));$page=min($page,$pages);
$st=$pdo->prepare('SELECT l.*, (SELECT COUNT(*) FROM lot_images li WHERE li.lot_id=l.id) AS photo_count, (SELECT li.path FROM lot_images li WHERE li.lot_id=l.id ORDER BY li.sort_order, li.id LIMIT 1) AS cover FROM lots l '.$w.' ORDER BY l.lot_number LIMIT ? OFFSET ?');
$i=1;foreach($p as $val)$st->bindValue($i++,$val);
$st->bindValue($i++,$perPage,PDO::PARAM_INT);$st->bindValue($i,($page-1)*$perPage,PDO::PARAM_INT);$st->execute();$rows=$st->fetchAll();
function dashboard_stat_url(array $filters): string { return url('admin/index.php', $filters); }
function dashboard_page_url(int $page): string { $p=$_GET;$p['pagina']=$page;return url('admin/index.php',$p); }
admin_top('Kaveloverzicht');
?>
<div class="dashboard-modern">
  <p class="dashboard-subtitle">Beheer, zoek en bewerk alle kavels in de veilingcatalogus.</p>
  <div class="dashboard-stats" aria-label="Catalogusstatistieken">
    <a class="dashboard-stat" href="<?=e(dashboard_stat_url([]))?>" aria-label="Alle kavels tonen"><span class="dashboard-icon" aria-hidden="true">◇</span><div><small>TOTAAL AANGEMAAKT</small><div><strong><?=e((string)$total)?></strong> kavels</div></div></a>
    <a class="dashboard-stat" href="<?=e(dashboard_stat_url(['status'=>'published']))?>" aria-label="Gepubliceerde kavels tonen"><span class="dashboard-icon" aria-hidden="true">◉</span><div><small>GEPUBLICEERD</small><div><strong><?=e((string)$published)?></strong> kavels zichtbaar</div></div></a>
    <a class="dashboard-stat" href="<?=e(dashboard_stat_url(['sale_location'=>'veiling']))?>" aria-label="Kavels in Veiling tonen"><span class="dashboard-icon" aria-hidden="true">▧</span><div><small>KAVELS IN VEILING</small><div><strong><?=e((string)$inAuction)?></strong> kavels</div></div></a>
    <a class="dashboard-stat" href="<?=e(dashboard_stat_url(['sale_location'=>'markt']))?>" aria-label="Kavels in Markt tonen"><span class="dashboard-icon" aria-hidden="true">◎</span><div><small>KAVELS IN MARKT</small><div><strong><?=e((string)$inMarket)?></strong> kavels</div></div></a>
    <a class="dashboard-stat dashboard-stat-extra" href="<?=e(dashboard_stat_url(['photos'=>'none']))?>" aria-label="Kavels zonder foto tonen"><span class="dashboard-icon" aria-hidden="true">▧</span><div><small>KAVELS ZONDER FOTO</small><div><strong><?=e((string)$withoutPhoto)?></strong> kavels</div></div></a>
  </div>
  <section class="dashboard-panel" aria-labelledby="dashboard-lots-title">
    <div class="dashboard-panel-heading"><div><h2 id="dashboard-lots-title">Alle kavels</h2><p><?=e((string)$filtered)?> resultaten. Ongepubliceerde kavels blijven onzichtbaar voor bezoekers.</p></div></div>
    <form class="dashboard-filters" method="get" role="search">
      <label class="dashboard-search"><span class="sr-only">Zoek kavel of titel</span><input type="search" name="q" placeholder="Zoek kavel of titel…" value="<?=e($q)?>"></label>
      <label><span class="sr-only">Categorie</span><select name="category"><option value="">Alle categorieën</option><?php foreach($categories as $cat): ?><option value="<?=e($cat)?>" <?=$category===$cat?'selected':''?>><?=e($cat)?></option><?php endforeach; ?></select></label>
      <label><span class="sr-only">Status</span><select name="status"><option value="">Alle statussen</option><option value="draft" <?=$status==='draft'?'selected':''?>>Concept</option><option value="published" <?=$status==='published'?'selected':''?>>Gepubliceerd</option><option value="sold" <?=$status==='sold'?'selected':''?>>Verkocht</option><option value="canceled" <?=$status==='canceled'?'selected':''?>>Canceled</option></select></label>
      <label><span class="sr-only">Verkoopplaats</span><select name="sale_location"><option value="">Alle verkoopplaatsen</option><option value="markt" <?=$saleLocation==='markt'?'selected':''?>>Markt</option><option value="veiling" <?=$saleLocation==='veiling'?'selected':''?>>Veiling</option></select></label>
      <?php if ($photoFilter==='none'): ?><input type="hidden" name="photos" value="none"><?php endif; ?>
      <button class="primary" type="submit">Zoeken</button>
    </form>
    <div class="dashboard-table-scroll"><table class="dashboard-table"><thead><tr><th scope="col">Nr.</th><th scope="col">Foto</th><th scope="col">Titel</th><th scope="col">Categorie</th><th scope="col">Verkoopplaats</th><th scope="col">Veilingsnummer</th><th scope="col">Status</th><th scope="col">Acties</th></tr></thead><tbody>
    <?php foreach($rows as $row): ?><tr>
      <td><strong><?=e(str_pad((string)$row['lot_number'],3,'0',STR_PAD_LEFT))?></strong></td>
      <td class="dashboard-cover-cell"><?php if(!empty($row['cover'])): ?><img class="dashboard-cover" src="<?=e(url($row['cover']))?>" alt="" loading="lazy" width="52" height="52"><?php else: ?><span class="dashboard-cover-empty" aria-label="Geen foto">▧</span><?php endif; ?></td>
      <td class="dashboard-title"><a href="<?=e(url('admin/kavel.php',['id'=>(int)$row['id']]))?>"><?=e($row['title'])?></a></td>
      <td><span class="dashboard-category"><?=e($row['category'])?></span></td>
      <td><?=e($row['sale_location'] === 'veiling' ? 'Veiling' : 'Markt')?></td>
      <td><?=($row['sale_location'] === 'veiling' && $row['auction_number'] !== null) ? e((string)$row['auction_number']) : ''?></td>
      <td><span class="dashboard-badge <?=$row['status']==='published'?'is-live':'is-draft'?>"><?=e(['draft'=>'● Concept','published'=>'● Gepubliceerd','sold'=>'● Verkocht','canceled'=>'● Canceled'][$row['status']] ?? '● Onbekend')?></span></td>
      <td><a class="dashboard-edit" href="<?=e(url('admin/kavel.php',['id'=>(int)$row['id']]))?>"><?=lot_can_edit(effective_admin_role(), (int)$_SESSION['admin_id'], $row) ? '✎ Bewerken' : 'Bekijken'?></a></td>
    </tr><?php endforeach; ?>
    <?php if(!$rows): ?><tr><td colspan="8">Geen kavels gevonden. <a href="<?=e(url('admin/index.php'))?>">Wis filters</a>.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($pages>1): ?><nav class="dashboard-pagination" aria-label="Paginering"><?php if($page>1): ?><a href="<?=e(dashboard_page_url($page-1))?>">← Vorige</a><?php endif; ?><span>Pagina <?=$page?> / <?=$pages?></span><?php if($page<$pages): ?><a href="<?=e(dashboard_page_url($page+1))?>">Volgende →</a><?php endif; ?></nav><?php endif; ?>
  </section>
</div>
<?php admin_bottom(); ?>
