<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
admin_top('Rollen & rechten');
?>
<section class="dashboard-panel">
  <h2>Wie kan wat doen?</h2>
  <p>De actieve rol bepaalt welke acties toegestaan zijn. Assistenten mogen uitsluitend eigen conceptkavels bewerken. Voor kavels zonder bekende maker zijn alleen de Veilingmeester en Administrator bevoegd. Ook fotoacties en verwijderingen volgen deze regels.</p><p>Publicatie op de Markt is verboden wanneer een kavel gemarkeerd is als uitsluitend bestemd voor de Veiling. De server weigert een conflicterende publicatie volledig.</p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Bevoegdheid</th><th>Administrator</th><th>Assistent</th><th>Veilingmeester</th></tr></thead>
      <tbody>
        <tr><td>Nieuw conceptkavel aanmaken</td><td>Ja</td><td>Ja</td><td>Ja</td></tr>
        <tr><td>Eigen conceptkavel bewerken of verwijderen</td><td>Ja</td><td>Ja</td><td>Ja</td></tr>
        <tr><td>Conceptkavel van een andere maker bewerken of verwijderen</td><td>Ja</td><td>Nee</td><td>Ja</td></tr>
        <tr><td>Kavel publiceren of publicatie terugdraaien</td><td>Ja</td><td>Nee</td><td>Ja</td></tr>
        <tr><td>Gepubliceerd kavel bewerken, foto's wijzigen of verwijderen</td><td>Ja</td><td>Nee</td><td>Ja</td></tr>
        <tr><td>Veilingorganisatie bekijken</td><td>Ja</td><td>Ja</td><td>Ja</td></tr>
        <tr><td>Veilingvolgorde wijzigen</td><td>Ja</td><td>Nee</td><td>Ja</td></tr>
        <tr><td>Gebruikersbeheer, instellingen, statistieken, back-ups en systeemcheck</td><td>Ja</td><td>Nee</td><td>Nee</td></tr>
        <tr><td>Tijdelijk van rol wisselen</td><td>Alleen oorspronkelijk administratoraccount</td><td>Nee</td><td>Nee</td></tr>
      </tbody>
    </table>
  </div>
</section>
<?php admin_bottom(); ?>
