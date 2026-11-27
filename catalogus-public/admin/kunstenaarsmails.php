<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_once dirname(__DIR__, 2) . '/private/catalog_mutex.php';
require_once dirname(__DIR__, 2) . '/private/lot_sellers.php';
require_auth();
if (effective_admin_role() !== 'administrator') {
  http_response_code(403);
  exit('Alleen administrators hebben toegang tot de kunstenaarsmails.');
}
header('Cache-Control: no-store, private');

$pdo = db();
if (is_post()) {
  check_csrf();
  $queueId = filter_var($_POST['queue_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($queueId === false) {
    http_response_code(400);
    exit('Ongeldig mailnummer.');
  }
  catalog_lock_for_request($pdo);
  $delete = $pdo->prepare('DELETE FROM artist_publication_mail_queue WHERE id=?');
  $delete->execute([$queueId]);
  if ($delete->rowCount() === 1) {
    flash('De mail is uit de wachtrij verwijderd.');
  } else {
    flash('Deze mail staat niet meer in de wachtrij.', 'warning');
  }
  redirect('admin/kunstenaarsmails.php');
}

$pending = $pdo->query('SELECT q.id, q.is_profile_update, s.artist_name, s.email
  FROM artist_publication_mail_queue q JOIN lot_sellers s ON s.id=q.seller_id
  ORDER BY q.id')->fetchAll();
$sent = $pdo->query('SELECT artist_name, is_profile_update, sent_at
  FROM artist_publication_mail_history ORDER BY id DESC LIMIT 10')->fetchAll();
$enabled = seller_artist_publication_mail_enabled($pdo);
$kindLabel = static fn(array $row): string => (int)$row['is_profile_update'] === 1 ? 'Pagina bijgewerkt' : 'Publicatie';

admin_top('Kunstenaarsmails');
?>
<p><a class="secondary" href="<?=e(url('admin/instellingen.php'))?>">← Terug naar Veilinginfo</a></p>
<div class="dashboard-modern">
  <section class="dashboard-panel" aria-labelledby="artist-mail-queue">
    <h2 id="artist-mail-queue">Wachtrij</h2>
    <p>Automatische verzending staat <strong><?=$enabled ? 'aan' : 'uit'?></strong>.</p>
    <?php if ($pending): ?>
      <div class="dashboard-table-scroll">
        <table class="dashboard-table">
          <thead><tr><th scope="col">Kunstenaar</th><th scope="col">E-mailadres</th><th scope="col">Melding</th><th scope="col">Actie</th></tr></thead>
          <tbody>
            <?php foreach ($pending as $mail): ?>
              <tr>
                <td><?=e($mail['artist_name'])?></td>
                <td><?=e($mail['email'])?></td>
                <td><?=e($kindLabel($mail))?></td>
                <td>
                  <form method="post" onsubmit="return confirm('Deze mail uit de wachtrij verwijderen?');">
                    <?=csrf_field()?>
                    <input type="hidden" name="queue_id" value="<?=(int)$mail['id']?>">
                    <button class="secondary" type="submit" aria-label="<?=e('Verwijder mail voor '.$mail['artist_name'])?>">Verwijderen</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p>Er staan geen mails in de wachtrij.</p>
    <?php endif; ?>
  </section>
  <section class="dashboard-panel" aria-labelledby="artist-mail-history">
    <h2 id="artist-mail-history">10 laatst verzonden mails</h2>
    <p>Deze meldingen zijn aan de mailserver aangeboden.</p>
    <?php if ($sent): ?>
      <div class="dashboard-table-scroll">
        <table class="dashboard-table">
          <thead><tr><th scope="col">Kunstenaar</th><th scope="col">Melding</th><th scope="col">Verzonden (Brussel)</th></tr></thead>
          <tbody>
            <?php foreach ($sent as $mail): ?>
              <tr>
                <td><?=e($mail['artist_name'])?></td>
                <td><?=e($kindLabel($mail))?></td>
                <td><?=e(seller_audit_time($mail['sent_at']))?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p>Er zijn nog geen verzonden mails geregistreerd.</p>
    <?php endif; ?>
  </section>
</div>
<?php admin_bottom(); ?>
