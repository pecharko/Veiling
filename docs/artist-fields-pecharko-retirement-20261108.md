# Kunstenaarvelden + opruimen oude Pecharko-landingspagina (één PR)

## Gewenst gedrag
- In elk kavel: optionele **Kunstenaar** en **Hyperlink kunstenaar**.
- Link zonder kunstenaarsnaam wordt in het formulier en via MySQL 8.4 CHECK geweigerd.
- Een aanwezige kunstenaar verschijnt op de cataloguskaart en de detailpagina.
- Alleen de naam is klikbaar wanneer de link geldig is. HTML wordt ge-escaped; alleen volledige http(s)-URLs worden als link weergegeven met `target="_blank" rel="noopener noreferrer"`.
- Verwijder `catalogus-public/pecharko.php` en `catalogus-public/assets/pecharko.css`; behoud de voetnoot "Designed by Pecharko" naar `https://pecharko-website.pages.dev/`.
- Historische `release-requests/` en `releases/` worden niet verwijderd: dit zijn audit/rollbackbewijzen, **geen** actieve Pecharko-websitebestanden.

## Veilig productieverloop — één code-PR, geen ongeautoriseerde productieactie

1. **Databasebackup + migratie apart:** bestaande kavels en foto’s veiligstellen buiten OVH. Controleer vóór migratie MySQL 8.4 en dat `artist_name` en `artist_url` ontbreken. Voer na de afzonderlijke databaseautorisatie uitsluitend `database/migrations/20261108_lot_artist_fields.sql` uit. MySQL DDL is niet transactioneel; controleer de twee kolommen, de CHECK-constraint, bestaande records en een negatieve INSERT-test op een geïsoleerde database. Geen migratie door een publiek PHP-verzoek.
2. **Authentieke source/remote SHA-baselines:** voer twee bestaande read-only workflows uit (beide main-only en zonder SFTP writes). De **gewone vier bronbestanden** worden via `.github/workflows/shared-ovh-readonly-preflight.yml` gescand met exacte `files=catalogus-public/admin/kavel.php,private/lot_artist.php,catalogus-public/index.php,catalogus-public/kavel.php` en `source_sha=<immutable PR-head>`: die haalt uitsluitend candidate Git-blobs op, zonder ze uit te voeren. Het **beschermde** `private/snapshots.php` mag NOOIT in deze gewone scan (expliciet verboden door read-only denylist), maar wordt afzonderlijk met de bestaande `.github/workflows/password-reset-snapshot-readonly.yml` op main via pinned SFTP gecontroleerd. Die tweede read-only scan leest de **huidige main-source** (historisch) en de echte remote SHA; de *nieuwe* snapshot source SHA wordt van de exacte PR-blob berekend en offline met de eigen protected adapter gevalideerd. Voeg vervolgens `releases/artist-fields-20261108.json` met 4 runtimebestanden én `releases/artist-snapshot-20261108.json` met precies 1 beschermd bestand **in dezelfde PR** toe. ABSENT voor de nieuwe helper mag niet worden geraden. Controleer toekomstige remote drift altijd opnieuw vlak voor upload.
3. **Beschermde snapshotrelease na DB-schema:** de **nieuwe** `.github/workflows/artist-snapshot-release.yml` en `scripts/deploy_artist_snapshot.py` behandelen uitsluitend `private/snapshots.php`, gebonden aan een afzonderlijk authentic remote-source manifest `releases/artist-snapshot-20261108.json`. De oorspronkelijke `protected-catalog-snapshot-20261106` blijft immutable; zijn oude source SHA mag niet worden hergebruikt of aangepast. De nieuwe workflow eist afzonderlijke owner-bound main-autoristatie en expliciete geverifieerde SQL-schema-acceptatie, plus pinned SFTP, drift/backup/rollback/readback.
4. **Reguliere PHP-code-release:** de bestaande `shared-ovh-release.yml`, met owner-bound exact main, pinned SSH hostkey, source/remote SHA-gates, back-up, rollback en readback. Upload de vier bestanden uitsluitend na schema en beschermde snapshotrelease. Zonder DB-migratie is deze nieuwe admincode **niet** veilig te activeren.
5. **Oude Pecharko-bestanden op OVH verwijderen:** alleen na een live read-only `preflight` van `.github/workflows/retire-pecharko-landing.yml`. De aparte uitgeschakelde `deploy`-modus is uitsluitend twee vaste paden, met SHA-eis, owner-bound main-commit, onafhankelijke preflight, codeback-up, delete-readback ABSENT en rollback bij gedeeltelijke fout. De globale allowlist en het algemene releasepad blijven onaangeroerd. Start deze fase pas met de afzonderlijke exacte productieautorisatie en na een gecontroleerde PHP-release. **Nooit dezelfde one-shot poging automatisch herhalen bij een ambigue uitkomst.**
6. **Acceptatie:** controleer in admin bewaren/bewerken, afwijzing URL-zonder-naam, publieke kaart en detail met en zonder link, de footer naar Cloudflare Pages, en HTTP 404 voor de oude `/pecharko.php` en `/assets/pecharko.css`. Verifieer SFTP-readbacks, machine-evidence en rollbackartifacts.

## Testen en risico's
- `php tests/lot_form_test.php` voert ook `tests/lot_artist_test.php` uit.
- `tests/kavel_integration_setup.php` voert de exacte migratie op uitsluitend een fictieve MySQL 8.4-testdatabase uit en bewijst de CHECK.
- `tests/kavel_http_integration.php` controleert de werkelijke admin-POST-handling, status, afwijzing, HTML-escaping en bewaren.
- `tests/retire_pecharko_landing_test.py` controleert vastgelegde delete-allowlist, preflight zonder writes, driftstop, delete-readback en herstel na de tweede delete-fout.
- Previewbranch `preview/ui` en de Mac-worktree worden niet overschreven. Bij deze expliciete `één PR + deploy`-opdracht is de implementatie geïsoleerd vanaf `main` gemaakt.

**Geen merge, workflowdispatch, OVH-mutatie of databasewijziging is door deze PR zelf geautoriseerd.**

## Snapshotcompatibiliteit (beslissing 8 november 2026)
Oude snapshots hoeven **niet** te worden ondersteund. Een snapshot zonder alle actuele kavelvelden, waaronder `artist_name` en `artist_url`, wordt bij verificatie geweigerd voordat een restore de database of foto's kan veranderen. Er worden geen historische kavelkolommen bijgevuld en de oude snapshotbestanden worden niet automatisch verwijderd. Nieuwe snapshots na de schemamigratie bewaren beide kunstenaarsvelden. Beveiligde backups, rollback, checksums en verificatie blijven verplicht.
