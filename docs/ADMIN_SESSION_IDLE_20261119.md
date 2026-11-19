# Issue #23 - beheerder-inactiviteit (voorbereiding, 19 november 2026)

**Status:** alleen geïsoleerde securitybranch; niet gemerged, niet uitgerold.

## Bestaand: veilig uitloggen

`admin/logout.php` vereist ingelogde sessie, POST en CSRF. GET geeft 405, en
een geldige POST vernietigt server-side sessie en cookie. Dit blijft ongewijzigd.

## Nieuwe inactiviteitsregeling

- Na 60 minuten zonder interactieve serveraanvraag verloopt de beheerderssessie.
  Ook een laat POST-verzoek op een kavel wordt dan **niet** verwerkt.
- De ongelezene-mailteller (`admin/mail-ongelezen.php`) mag deze termijn
  **niet** automatisch verlengen.
- Bij nieuwe login wordt de timer gestart. Bestaande sessies zonder timer
  krijgen bij de eerstvolgende authentieke actie een starttijd.
- In beheerpagina's verschijnt tien minuten vóór de verwachte vervaldatum
  een waarschuwing. Met **Sessie verlengen** kan de gebruiker expliciet
  een POST met CSRF-token naar `admin/sessie-verlengen.php` sturen.
- Er worden geen formuliergegevens automatisch opgeslagen en er worden geen
  kavels, verkopers of persoonsgegevens in browseropslag gekopieerd.
- Als verlengen mislukt of de sessie verlopen is, blijft de bestaande
  formulierinhoud voorlopig zichtbaar om handmatig te kopiëren. Een volgend
  beveiligd verzoek vergt opnieuw aanmelden.
- Rollen, wachtwoordcontrole, alle bestaande CSRF-gates en de bestaande
  HTTPS-/SameSite-cookies blijven behouden.

## Verplichte resterende verificatie vóór PR en productie

1. PHP-lint op alle gewijzigde PHP-bestanden en de nieuwe helper/endpoint.
2. Gerichte pure beleidsregressie: grens 59:59/60:00, corrupte toekomstige
   tijd, eerste login en passieve mailpolls.
3. Geïsoleerde HTTP/browserproef voor Administrator, Veilingmeester en
   Assistent: verlengen met geldig/ongeldig CSRF, GET-405, verlopen sessie,
   heraanmelding, en beschermde route na expiry.
4. Desktop/mobiel: waarschuwing bij niet-opgeslagen kavel-/verkopergegevens,
   schermlezer/toetsenbord, venster op achtergrond/slaapstand, tabs tegelijk,
   falend netwerk, browserklokafwijking en aflopende sessie tijdens opslaan.
5. Controleer alle andere automatische adminverzoeken op onbedoelde
   sessieverlenging.
6. Handmatige beveiligingsreview, formele mergeautorisatie en afzonderlijke
   productieautorisatie inclusief read-only remote baseline en rollback.

**Geen** database-, cron-, account-, production config- of releasegatewijzigingen.

## Definitieve productbeslissing eigenaar (19 november 2026)

De eigenaar kiest **geen automatische herstelmogelijkheid voor niet-opgeslagen formulieren**. Bij sessieverval mogen niet-opgeslagen kavel-, verkoper- en kunstenaarsgegevens verloren gaan. Er komt geen draft-opslag in de browser, op de server of in de database, en geen herstel van tijdelijke gegevens na opnieuw aanmelden. De reeds ontworpen waarschuwing 10 minuten vooraf en de expliciete knop om een geldige sessie te verlengen blijven behouden. Een verlopen sessie mag geen opslag-POST uitvoeren. De zichtbare formulierinhoud zolang de bestaande pagina openblijft is geen gegarandeerde herstelmogelijkheid: verversen/navigeren/inloggen kan de gegevens doen verdwijnen. Dit besluit betekent geen toestemming om bestaande opgeslagen productiegegevens te verwijderen.
