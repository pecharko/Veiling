<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
require_once dirname(__DIR__, 2) . '/private/lot_permissions.php';
$pdo = db();
$s = settings();
$mine = ($_GET['mine'] ?? '') === '1';
// The scope switch applies to every dashboard statistic and the results table.
$scopeCount = static function (string $condition) use ($pdo, $mine): int {
  $sql = 'SELECT COUNT(*) FROM lots l WHERE '.$condition;
  if ($mine) $sql .= ' AND l.created_by_admin_id=?';
  $statement = $pdo->prepare($sql);
  $statement->execute($mine ? [(int)$_SESSION['admin_id']] : []);
  return (int)$statement->fetchColumn();
};
$total = $scopeCount('1=1');
$published = $scopeCount("l.status='published'");
$inAuction = $scopeCount("l.sale_location='veiling'");
$inMarket = $scopeCount("l.sale_location='markt'");
$withoutPhoto = $scopeCount('NOT EXISTS (SELECT 1 FROM lot_images li WHERE li.lot_id=l.id)');
$q = mb_substr(trim((string)($_GET['q'] ?? '')),0,100);
$category = mb_substr(trim((string)($_GET['category'] ?? '')),0,100);
$categorySql = "SELECT DISTINCT category FROM lots WHERE category IS NOT NULL AND category <> ''";
if ($mine) $categorySql .= ' AND created_by_admin_id=?';
$categorySql .= ' ORDER BY category';
$categoryStatement = $pdo->prepare($categorySql);
$categoryStatement->execute($mine ? [(int)$_SESSION['admin_id']] : []);
$categories = $categoryStatement->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($category,$categories,true)) $category='';
$status = in_array($_GET['status'] ?? '', ['draft','published','sold','canceled'], true) ? (string)$_GET['status'] : '';
$saleLocation = in_array($_GET['sale_location'] ?? '', ['markt','veiling'], true) ? (string)$_GET['sale_location'] : '';
$photoFilter = ($_GET['photos'] ?? '') === 'none' ? 'none' : '';
$page = max(1,min(1000,(int)($_GET['pagina'] ?? 1)));
$perPage = in_array((int)($_GET['per_page'] ?? 20),[20,50,100],true) ? (int)($_GET['per_page'] ?? 20) : 20;
$where = []; $p = [];
if ($q!=='') { $where[] = '(CAST(lot_number AS CHAR) LIKE ? OR title LIKE ? OR category LIKE ?)'; $p=array_fill(0,3,'%'.$q.'%'); }
if ($category!=='') { $where[]='category=?'; $p[]=$category; }
if ($status!=='') { $where[]='status=?'; $p[]=$status; }
if ($saleLocation!=='') { $where[]='sale_location=?'; $p[]=$saleLocation; }
if ($photoFilter==='none') { $where[]='NOT EXISTS (SELECT 1 FROM lot_images li WHERE li.lot_id=l.id)'; }
if ($mine) { $where[]='l.created_by_admin_id=?'; $p[]=(int)$_SESSION['admin_id']; }
$w = $where ? 'WHERE '.implode(' AND ',$where) : '';
$count=$pdo->prepare('SELECT COUNT(*) FROM lots l '.$w);$count->execute($p);
$filtered=(int)$count->fetchColumn();
$pages=max(1,(int)ceil($filtered/$perPage));$page=min($page,$pages);
$st=$pdo->prepare('SELECT l.*, seller.name AS seller_name, (SELECT COUNT(*) FROM lot_images li WHERE li.lot_id=l.id) AS photo_count, (SELECT li.path FROM lot_images li WHERE li.lot_id=l.id ORDER BY li.sort_order, li.id LIMIT 1) AS cover FROM lots l LEFT JOIN lot_sellers seller ON seller.id=l.seller_id '.$w.' ORDER BY l.lot_number LIMIT ? OFFSET ?');
$i=1;foreach($p as $val)$st->bindValue($i++,$val);
$st->bindValue($i++,$perPage,PDO::PARAM_INT);$st->bindValue($i,($page-1)*$perPage,PDO::PARAM_INT);$st->execute();$rows=$st->fetchAll();
function dashboard_stat_url(array $filters, bool $mine): string { if ($mine) $filters['mine']='1'; return url('admin/index.php', $filters); }
function dashboard_page_url(int $page): string { $p=$_GET;$p['pagina']=$page;return url('admin/index.php',$p); }
$allParams = array_intersect_key($_GET, array_flip(['q','category','status','sale_location','photos','per_page']));
$ownParams = $allParams;
$ownParams['mine']='1';
admin_top('Kaveloverzicht');
?>
<div class="dashboard-modern">
  <div class="dashboard-scope" aria-label="Hoofdfilter voor het volledige kaveloverzicht">
    <div class="dashboard-scope-heading"><strong>Filter het volledige overzicht</strong></div>
    <div class="dashboard-scope-options" role="group" aria-label="Welke kavels tonen?">
      <a class="dashboard-stat dashboard-scope-option <?=$mine?'':'is-selected'?>" href="<?=e(url('admin/index.php',$allParams))?>" <?=$mine?'':'aria-current="page"'?>><span class="dashboard-icon" aria-hidden="true">◇</span><span><strong>Alle kavels</strong><small>Van alle medewerkers</small></span><span class="dashboard-scope-check" aria-hidden="true"><?=$mine?'':'✓'?></span></a>
      <a class="dashboard-stat dashboard-scope-option <?=$mine?'is-selected':''?>" href="<?=e(url('admin/index.php',$ownParams))?>" <?=$mine?'aria-current="page"':''?>><span class="dashboard-icon" aria-hidden="true">◉</span><span><strong>Mijn kavels</strong><small>Alleen door mij aangemaakt</small></span><span class="dashboard-scope-check" aria-hidden="true"><?=$mine?'✓':''?></span></a>
    </div>
  </div>
  <div class="dashboard-stats" aria-label="Catalogusstatistieken">
    <a class="dashboard-stat" href="<?=e(dashboard_stat_url([], $mine))?>" aria-label="<?=$mine?'Al mijn kavels tonen':'Alle kavels tonen'?>"><span class="dashboard-icon" aria-hidden="true">◇</span><div><small>TOTAAL AANGEMAAKT</small><div><strong><?=e((string)$total)?></strong> kavels</div></div></a>
    <a class="dashboard-stat" href="<?=e(dashboard_stat_url(['status'=>'published'], $mine))?>" aria-label="Gepubliceerde kavels tonen"><span class="dashboard-icon" aria-hidden="true">◉</span><div><small>GEPUBLICEERD</small><div><strong><?=e((string)$published)?></strong> kavels zichtbaar</div></div></a>
    <a class="dashboard-stat" href="<?=e(dashboard_stat_url(['sale_location'=>'veiling'], $mine))?>" aria-label="Kavels in Veiling tonen"><span class="dashboard-icon" aria-hidden="true">▧</span><div><small>KAVELS IN VEILING</small><div><strong><?=e((string)$inAuction)?></strong> kavels</div></div></a>
    <a class="dashboard-stat" href="<?=e(dashboard_stat_url(['sale_location'=>'markt'], $mine))?>" aria-label="Kavels in Markt tonen"><span class="dashboard-icon" aria-hidden="true">◎</span><div><small>KAVELS IN MARKT</small><div><strong><?=e((string)$inMarket)?></strong> kavels</div></div></a>
    <a class="dashboard-stat dashboard-stat-extra" href="<?=e(dashboard_stat_url(['photos'=>'none'], $mine))?>" aria-label="Kavels zonder foto tonen"><span class="dashboard-icon" aria-hidden="true">▧</span><div><small>KAVELS ZONDER FOTO</small><div><strong><?=e((string)$withoutPhoto)?></strong> kavels</div></div></a>
  </div>
  <section class="dashboard-panel" aria-labelledby="dashboard-lots-title">
    <div class="dashboard-panel-heading"><div><h2 id="dashboard-lots-title"><?=$mine?'Mijn kavels':'Alle kavels'?></h2><p><?=e((string)$filtered)?> resultaten. Ongepubliceerde kavels blijven onzichtbaar voor bezoekers.</p></div><div class="dashboard-lot-actions"><?php if (lot_can_create(effective_admin_role())): ?><a class="primary" href="<?=e(url('admin/kavel.php'))?>">+ Nieuw kavel</a><?php endif; ?></div></div>
    <form class="dashboard-filters" method="get" role="search">
      <label class="dashboard-search"><span class="sr-only">Zoek kavel of titel</span><input type="search" name="q" placeholder="Zoek kavel of titel…" value="<?=e($q)?>"></label>
      <label><span class="sr-only">Categorie</span><select name="category"><option value="">Alle categorieën</option><?php foreach($categories as $cat): ?><option value="<?=e($cat)?>" <?=$category===$cat?'selected':''?>><?=e($cat)?></option><?php endforeach; ?></select></label>
      <label><span class="sr-only">Status</span><select name="status"><option value="">Alle statussen</option><option value="draft" <?=$status==='draft'?'selected':''?>>Nieuw</option><option value="published" <?=$status==='published'?'selected':''?>>Gepubliceerd</option><option value="sold" <?=$status==='sold'?'selected':''?>>Verkocht</option><option value="canceled" <?=$status==='canceled'?'selected':''?>>Canceled</option></select></label>
      <label><span class="sr-only">Verkoopplaats</span><select name="sale_location"><option value="">Alle verkoopplaatsen</option><option value="markt" <?=$saleLocation==='markt'?'selected':''?>>Markt</option><option value="veiling" <?=$saleLocation==='veiling'?'selected':''?>>Veiling</option></select></label>
      <label><span class="sr-only">Aantal kavels per pagina</span><select name="per_page" aria-label="Kavels per pagina"><?php foreach([20,50,100] as $limit): ?><option value="<?=$limit?>" <?=$perPage===$limit?'selected':''?>><?=$limit?> per pagina</option><?php endforeach; ?></select></label>
      <?php if ($photoFilter==='none'): ?><input type="hidden" name="photos" value="none"><?php endif; ?>
      <?php if ($mine): ?><input type="hidden" name="mine" value="1"><?php endif; ?>
      <button class="primary" type="submit">Zoeken</button>
    </form>
    <div class="dashboard-table-scroll"><table class="dashboard-table"><thead><tr><th scope="col">Nr.</th><th scope="col">Foto</th><th scope="col">Titel</th><th scope="col">Categorie</th><th scope="col">Verkoper</th><th scope="col">Verkoopplaats</th><th scope="col">Status</th><th scope="col">Acties</th></tr></thead><tbody>
    <?php foreach($rows as $row): ?><tr>
      <td><strong><?=e(str_pad((string)$row['lot_number'],3,'0',STR_PAD_LEFT))?></strong></td>
      <td class="dashboard-cover-cell"><?php if(!empty($row['cover'])): ?><img class="dashboard-cover" src="<?=e(url($row['cover']))?>" alt="" loading="lazy" width="52" height="52"><?php else: ?><span class="dashboard-cover-empty" aria-label="Geen foto">▧</span><?php endif; ?></td>
      <td class="dashboard-title"><a href="<?=e(url('admin/kavel.php',['id'=>(int)$row['id']]))?>"><?=e($row['title'])?></a></td>
      <td><span class="dashboard-category"><?=e($row['category'])?></span></td>
      <td class="dashboard-seller"><?=($row['seller_name'] ?? '') !== '' ? e((string)$row['seller_name']) : '—'?></td>
      <td><?=e($row['sale_location'] === 'veiling' ? 'Veiling' : 'Markt')?></td>
      <td><span class="dashboard-badge <?=e(['draft'=>'is-new','published'=>'is-published','sold'=>'is-sold','canceled'=>'is-canceled'][$row['status']] ?? 'is-new')?>"><?=e(['draft'=>'Nieuw','published'=>'Gepubliceerd','sold'=>'Verkocht','canceled'=>'Canceled'][$row['status']] ?? 'Onbekend')?></span></td>
      <td><a class="dashboard-edit" href="<?=e(url('admin/kavel.php',['id'=>(int)$row['id']]))?>"><?=lot_can_edit(effective_admin_role(), (int)$_SESSION['admin_id'], $row) ? '✎ Bewerken' : 'Bekijken'?></a></td>
    </tr><?php endforeach; ?>
    <?php if(!$rows): ?><tr><td colspan="8">Geen kavels gevonden. <a href="<?=e(url('admin/index.php'))?>">Wis filters</a>.</td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="dashboard-pagination" aria-label="Paginering kavels"><span><?=($filtered ? (($page-1)*$perPage+1) : 0)?>–<?=min($filtered,$page*$perPage)?> van <?=$filtered?> kavels</span><?php if($page>1): ?><a href="<?=e(dashboard_page_url($page-1))?>">← Vorige</a><?php endif; ?><span>Pagina <?=$page?> / <?=$pages?></span><?php if($page<$pages): ?><a href="<?=e(dashboard_page_url($page+1))?>">Volgende →</a><?php endif; ?></nav>
  </section>
</div>
<?php admin_bottom(); ?>
