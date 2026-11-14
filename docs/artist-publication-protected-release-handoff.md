# Beveiligde overdracht — kunstenaarspublicatie

**Status:** uitsluitend ontwikkel- en reviewdocument. Niet uitvoeren als deploy-opdracht.
**Functionele PR:** #582 (`preview/artist-publication-20261114`).
**Beschermde snapshot-PR:** #583 (base: featurebranch #582).
**Niet wijzigen:** bestaande protected-releasegates, gepinde hashes, historische snapshots, productiegegevens.
**Eigenaarskeuze:** oudere snapshots hoeven na de migratie niet herstelbaar te blijven; alle bestaande snapshotbestanden en records blijven behouden.

## Inhoud

- **Additieve DB-migratie:** `database/migrations/20261114_artist_publication.sql` voegt `lot_sellers.artist_published TINYINT(1) NOT NULL DEFAULT 0` toe. Geen kolommen, records of foto's worden verwijderd. Bestaande kunstenaars worden aanvankelijk niet-gepubliceerd totdat bevoegd gepubliceerd.
- **Gewone runtimebestanden:**
  `catalogus-public/admin/rollen.php`, `catalogus-public/admin/verkoper.php`,
  `catalogus-public/admin/verkopers.php`, `catalogus-public/index.php`,
  `catalogus-public/kavel.php`, `catalogus-public/kunstenaar.php`,
  `catalogus-public/kunstenaars.php`, `private/lot_artist.php`,
  `private/lot_sellers.php`.
- **Beschermde runtime:** `private/snapshots.php` (alleen nieuwe publicatiekolom aan de bestaande snapshot-allowlist).
- **Inerte voorbereidingscode:** `scripts/deploy_artist_publication_snapshot.py` en offline unittests, zonder geactiveerde workflow of productiemanifest.

## Fail-closed bewijsgates vóór een verzoek om merge

1. Hercontroleer immutable `main`, actuele head, volledige diff en bestandslijst van #582 en #583. #583 mag ten opzichte van zijn featurebasis alleen de beschermde snapshotbron en bijbehorende offline voorbereiding/testdocumentatie bevatten.
2. Vereis geslaagde, actuele PHP, preview- en HTTP/MySQL-tests op #582, en geslaagde snapshot/restore- en beveiligingsadaptertests op de **gecombineerde** bron van #583.
3. Verifieer de reeds aangetoonde historische releasechecks als *afzonderlijke pinned controles*, niet als goedkeuring voor de nieuwe snapshotbron. Hun vaste hashes, releasecontrollers en workflows blijven onveranderd.
4. Een nieuwe werkende protected-releaseworkflow, een nieuw manifest en elke aanpassing aan releasebevoegdheden zijn **niet** inbegrepen in de bestaande offline-ontwikkelautorisatie. Vereisen afzonderlijke expliciete autorisatie.
5. Vraag een **exacte** squash-mergeautorisatie voor iedere toepasselijke PR-head, main-baseline en gewijzigde bestanden; nooit afleiden uit `go` of `deploy`.

## Verplichte productiepreflight — pas ná afzonderlijke autorisaties

1. Identificeer de exact te gebruiken nieuwe beveiligde releaseprocedure. Geen hergebruik of versoepeling van historische pinned v7- en artist-slogan-workflows voor een andere bronhash.
2. Verzamel een **verse, geauthenticeerde read-only** SHA-256-baseline per gewoon runtimebestand én `private/snapshots.php` via gepinde SFTP-hostkey. Een oude manifesthash, groen CI-resultaat of lokale vergelijking is geen bewijs voor de huidige OVH-toestand.
3. Maak een manifest met precies het nieuwe immutable `main`-commit, exacte bronbytes, `source_sha256` en geauthenticeerde `remote_sha256` per pad. Geen fictieve of overgenomen hashes en geen `ABSENT` voor de protected snapshotbron.
4. Verifieer onafhankelijk een **volledige productiedatabaseback-up buiten OVH**, rollback, voldoende opslag, onderhoudsvenster, en exacte DB-schema- en bestandscompatibiliteit.
5. Verifieer de handmatige eenmalige DB-migratie volledig in geïsoleerde MySQL met fictieve data, plus een afzonderlijk door de eigenaar goedgekeurd productie-DB-writepad. Geen SQL-opdracht of DB-query op productie zonder voorafgaande SQL-kwaliteitsgate.
6. Leg de overgang zo vast dat geen verzoek `artist_published` leest terwijl de kolom ontbreekt, en de oude snapshot-allowlist niet wordt gebruikt na de migratie. De volgorde en eventuele onderhoudsstand vereisen eigen operationele controle.
7. Alleen na afzonderlijke, op het exacte manifest gerichte productieautorisatie: verse remote-driftcontrole, gepinde hostkey, terugrolbare bestandsback-up, readback per bestand, bewijs, onafhankelijke read-only OVH-reconciliatie en browseracceptatie.
8. Bij een ambigue externe schrijfuitkomst: **STOP, GEEN RETRY**, alleen onafhankelijke read-only reconciliatie.

## Open blokkades

- Geen actueel geauthenticeerd remote-baselinebewijs voor deze nieuwe bron.
- Geen geautoriseerde actieve protected-releaseworkflow of exact productie-manifest.
- Geen afzonderlijke mergeautorisaties of productiedatabasemigratie-/productietoestemming.
- Niet verwarren met de groene offline mocks: die bewijzen fail-closed contracts, geen daadwerkelijke productieautorisatie of live herstel.

**Expliciet verboden in deze voorbereiding:** merge, workflowdispatch, OVH-upload, databasewrite, snapshot-create/restore, secrets-, rechten-, cron- of releasegatewijziging.
