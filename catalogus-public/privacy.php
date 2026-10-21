<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
$s = settings();
$contact = trim((string)($s['contact_email'] ?? ''));
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Privacy · 41 Lokeren</title>
<link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>">
<link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>">
</head>
<body>
<main class="shell" style="max-width:780px;padding:48px 20px 72px">
  <p><a href="<?=e(url('index.php'))?>">← Terug naar de catalogus</a></p>
  <h1>Privacy en bezoekersstatistieken</h1>
  <p>De veilingwebsite van 41 Lokeren gebruikt eigen, cookievrije bezoekersstatistieken om paginaweergaven en belangstelling voor kavels te meten. Er worden hiervoor geen externe advertentie- of statistiekdiensten gebruikt.</p>
  <p>Bij een bezoek worden het IP-adres en de browserinformatie kort verwerkt om met een geheime sleutel een per UTC-dag wisselende, gepseudonimiseerde bezoekerscode te berekenen. De oorspronkelijke IP-adressen en browserinformatie worden niet in de statistiekentabel opgeslagen. Pseudonieme gegevens kunnen nog persoonsgegevens zijn.</p>
  <p>De statistiekentabel bevat de bezoekdatum, de gepseudonimiseerde code, het paginatype, eventueel een interne kavel-ID en het aantal paginaweergaven. Een browser die een Do Not Track-signaal meestuurt, wordt niet door deze statistiekenmodule geregistreerd.</p>
  <p>Bezoekersstatistieken worden uitsluitend door bevoegde beheerders bekeken. Beheerders kunnen alle statistieken of alle records vóór een gekozen datum handmatig verwijderen. Er is geen automatische opschoning.</p>
  <p>De organisator dient vóór de activering van deze statistieken de rechtsgrond en concrete maximale bewaartermijn vast te stellen en hierover de informatie aan te vullen. Technische webserverlogs van de hosting vallen buiten deze statistiekenmodule.</p>
  <?php if ($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL)): ?>
    <p>Voor vragen over privacy en je rechten kun je contact opnemen via <a href="mailto:<?=e($contact)?>"><?=e($contact)?></a>.</p>
  <?php else: ?>
    <p>Het contactadres van de verantwoordelijke organisatie moet vóór activering van bezoekersstatistieken worden ingevuld.</p>
  <?php endif; ?>
</main>
</body>
</html>
