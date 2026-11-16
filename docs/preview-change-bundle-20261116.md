# Gezamenlijke wijzigingsbundel — 16 november 2026

**Ontwikkelbranch:** `preview/be-only-analytics-20261116`.
**Basis:** `main` op `0252f80dc54fc649c1cedb4a4e219f7bccc9f0b9`.
**Fase:** wijzigingen verzamelen en lokaal testen; nog geen PR, merge of productieactie.

## 1. Alleen Belgische bezoekers in statistieken

Nieuwe registratie is uitsluitend toegestaan bij door de lokale GeoLite2-database
bevestigde landcode `BE`. Buitenlandse, ongeldige en onbekende herkomst wordt
voor bezoeker-ID, dag-/kavel-/geostatistiek en latere archivering geweigerd.
Bestaande archieven en statistieken worden niet verwijderd.
Relevante details en tests: [visitor-stats-be-only.md](visitor-stats-be-only.md).

**Status:** backend, tekst en gerichte contracttests voorbereid; volledige
integratiereeks en positieve controle met Belgische GeoIP-fixture open.

## 2. Actieve knop voor geïnteresseerde kunstenaars

De knop **Ik ben kunstenaar en heb interesse** op de startpagina linkt binnen
dezelfde website naar `kunstenaar-interesse.php`. De pagina bevat de volledige
aangeleverde uitnodiging, het klikbare adres `veiling@41lokeren.be`, duidelijke
afspraken over het zelf te kiezen schenkingpercentage en de beloofde zichtbaarheid
via website/sociale media. Een compacte voorbeeldbrochure toont de opbouw van de bestaande
kunstenaarspagina met **echt opgeslagen beelden**: bij voorkeur de bestaande
fictieve previewkunstenaar **Mira Veld**, haar portret en haar drie eigen werken.
De fotopaden worden even streng gecontroleerd als op de echte kunstenaarspagina;
de pagina toont alleen expliciet gepubliceerde kunstenaars. Als Mira buiten de
preview ontbreekt, kan een andere reeds gepubliceerde kunstenaar met vier geldige
foto's als voorbeeld dienen, zonder deze als fictief te bestempelen.
Het voorbeeld is bewust kleiner gemaakt (maximaal 300 px breed). De aparte knop **Ik heb een kunstwerk voor de veiling**
blijft bewust uitgeschakeld.

**Broncode:** `catalogus-public/index.php`,
`catalogus-public/kunstenaar-interesse.php`,
`catalogus-public/assets/home-v18.css`,
`catalogus-public/assets/kunstenaar-interesse.css`.

**Gerichte tests:**
- `php tests/artist_interest_page_contract_test.php`
- `php tests/good_cause_page_contract_test.php`
- `node dev/preview/artist_interest_browser_test.cjs` (Docker-preview op
  localhost:8080; lokale Playwright en Chrome)
- `php -l catalogus-public/kunstenaar-interesse.php`

**Status:** geïmplementeerd in GitHub-ontwikkelbranch; broncontracten statisch
nagekeken. PHP-lint en daadwerkelijke browseracceptatie moeten nog op de Mac
worden uitgevoerd. Bestaande fictieve testkunstenaars, foto’s, data en Docker
volumes blijven behouden.

## Releasegrenzen

Beide wijzigingen blijven tot de gekozen bundel compleet is op dezelfde
ontwikkelbranch. Nog geen PR of releasemanifest; bij later finaliseren
worden exacte diff, tests, CI, beveiligde publicatieroute en gescheiden
merge-/productieautorisaties opnieuw gecontroleerd.
