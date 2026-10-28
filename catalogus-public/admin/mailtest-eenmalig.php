<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_administrator();
require_no_index();
header('Referrer-Policy: no-referrer');
$path = dirname(__DIR__, 2) . '/private/.mail_probe_20261028_attempted';
$state = is_file($path) ? 'Deze eenmalige test is al gestart.' : '';
if (is_post()) {
    check_csrf();
    if (!hash_equals('VERSTUUR-EEN-TESTMAIL', (string)($_POST['confirmation'] ?? ''))) {
        $state = 'Vul de exacte bevestiging in.';
    } else {
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            $state = 'De test is al uitgevoerd of de marker kan niet veilig worden gemaakt.';
        } else {
            fwrite($fh, gmdate('c') . " attempted\n");
            fclose($fh);
            $headers = [
                'From' => '41 Lokeren <noreply@41lokeren.be>',
                'Reply-To' => 'noreply@41lokeren.be',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ];
            try {
                $ok = mail('chris.pecharko@gmail.com', 'Testmail 41 Lokeren',
                    "Dit is een eenmalige mailtest van de OVH PHP-mailfunctie.\nGeen actie nodig.\n", $headers);
                $state = $ok ? 'PHP heeft de mail aanvaard. Controleer de ontvangst in Gmail.' : 'PHP-mailfunctie heeft het bericht niet aanvaard.';
            } catch (Throwable $exception) {
                error_log('Mail probe transport error');
                $state = 'Mailtransportfout, er wordt niet automatisch opnieuw geprobeerd.';
            }
        }
    }
}
admin_top('Eenmalige mailtest');
?><section class="card"><p>Test uitsluitend voor de Administrator, vanaf noreply@41lokeren.be naar chris.pecharko@gmail.com.</p><p>Deze test kan maar één keer starten. De marker wordt vóór het versturen vastgelegd. Geen automatische herhaling.</p><?php if($state): ?><div class="alert" role="status"><?=e($state)?></div><?php endif; ?><?php if(!is_file($path)): ?><form method="post"><?=csrf_field()?><label>Typ VERSTUUR-EEN-TESTMAIL om te bevestigen<input type="text" name="confirmation" required autocomplete="off"></label><button class="primary" type="submit">Eén testmail versturen</button></form><?php endif; ?></section><?php admin_bottom();
