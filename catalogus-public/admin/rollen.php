<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
admin_top('Rollen & rechten');
?>
<section class="dashboard-panel">
  <h2>Wie kan wat doen?</h2>
  <p>De actieve rol bepaalt welke onderdelen van het beheermenu zichtbaar zijn en welke acties toegestaan zijn. De rol staat steeds rechts in de menubalk.</p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Bevoegdheid</th><th>Administrator</th><th>Assistent</th><th>Veilingmeester</th></tr></thead>
      <tbody>
        <tr><td>Kavels bekijken en beheren</td><td>Ja</td><td>Ja</td><td>Ja</td></tr>
        <tr><td>Veilingorganisatie bekijken</td><td>Ja</td><td>Ja</td><td>Ja</td></tr>
        <tr><td>Veilingvolgorde wijzigen</td><td>Ja</td><td>Nee</td><td>Ja</td></tr>
        <tr><td>Gebruikersbeheer, instellingen, statistieken, back-ups en systeemcheck</td><td>Ja</td><td>Nee</td><td>Nee</td></tr>
        <tr><td>Tijdelijk van rol wisselen</td><td>Alleen oorspronkelijk administratoraccount</td><td>Nee</td><td>Nee</td></tr>
      </tbody>
    </table>
  </div>
</section>
<?php admin_bottom(); ?>
