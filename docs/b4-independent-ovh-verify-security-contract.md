# B4 — onafhankelijke OVH-verificatie: uitvoeringscontract (ontwerp, niet actief)

Status: **ONTWERP — geen workflow, credentials, productieactie of acceptatie geactiveerd**.

## Doel en trust boundary

Een tweede, afzonderlijk aangeroepen uitvoering leest de werkelijke OVH-bestanden **na** een geslaagde, exact gebonden gedeelde release. GitHub Actions release-artifacts en een SFTP-readback door dezelfde releasejob vormen geen onafhankelijk bewijs. Een groen CI-resultaat is nooit browseracceptatie.

## Voorwaarden vóór een uitvoerende PR

1. Gebruik uitsluitend de bestaande `scripts/controller_b4_ovh_readback.py` (met clean checkout en exact SHA) plus `scripts/shared_ovh_release.py` voor de bestaande gehostkeypinde SFTP-lezing; geen tweede uploader.
2. Een gecontroleerde dispatch voor **verificatie, niet deployment**, neemt expliciet `release_id`, 40-hex `release_sha`, positief `release_run_id` en een afzonderlijke bevestiging. Faal bij ontbrekende/onbekende input. Geen automatische `workflow_run`-trigger met productiegeheimen.
3. Lees de bedoelde release-run met `Actions:read` en controleer onafhankelijk: repository `pecharko/Veiling`, exact workflowpad `.github/workflows/shared-ovh-release.yml`, `workflow_dispatch`, branch `main`, exacte `head_sha`, `status=completed`, `conclusion=success`, job/stepidentiteit en exact run-ID. Hanteer bestaande Phase A/B1-binder; onbekende/paginated/missing evidence = STOP.
4. Controleer de immutable releasecommit en het manifest op diezelfde commit voordat OVH wordt aangeraakt. Checkout **exact** `release_sha` (niet een later `main`), `persist-credentials:false`, geen schrijfbare workspace-artifacts vóór de clean-treecontrole. Git SHA en alle bronhashes moeten overeenkomen; `controller_b4_ovh_readback.py` weigert dirty/untracked state.
5. Voor OVH is een **eigen SFTP-account met uitsluitend leesrechten op de noodzakelijke cataloguspaden** de voorkeur. De bestaande deployment-SFTP-secret is write-capable: die aan een afzonderlijke workflow geven is géén least privilege. Stel afzonderlijke credential availability, OVH host key en accountrechten vooraf vast. Tot dan: **geen uitvoering/secrettoekenning**. Maak geen nieuw secret of GitHub environment zonder aparte goedkeuring.
6. Verificatie leest alleen de door het manifest geselecteerde, toegestane runtimebestanden, met bestaande bestandsgroottegrenzen, hostkeypinning en remote-root-sentinel. Geen upload, mkdir, delete, chmod, shell, DB, cron of rollback. Vergelijk per pad SHA-256 met `source_sha256`. Een mismatch of ontbrekend bestand blijft rood.
7. De verificatie maakt een beperkt machineleesbaar bewijs: `release_id`, `release_sha`, `release_run_id`, onafhankelijke verify-run-ID, exacte bestandslijst, per-pad expected/observed SHA-256, timestamps, resultaat en context van gecontroleerde herkomst. Geen wachtwoorden, tokens, hostkeys of bestandinhoud. Vertrouw deze provenance pas na teruglezen van de GitHub-run/-artifact via read-only API; een lokaal JSON-veld `verified_by_trusted_reader=true` is onvoldoende.
8. Geef alleen `OVH_READBACK_OBSERVED` (nooit `ACCEPTED`). De browseracceptatie moet afzonderlijk, door de geauthenticeerde eigenaar, aan exact dezelfde release-run/SHA/ID zijn gebonden. B4 GitHub-registratie is een **aparte, gecontroleerde stap**, met server-side hercontrole, serialisatie en unieke/replayveilige gate voor schrijven.
9. Geen aanpassing aan `.github/workflows/shared-ovh-release.yml` of `verified_deploy_controller.py`; geen productieautorisatie uit een CI-merge afleiden. Beperk GitHub-token tot `contents:read` en `actions:read`, tenzij een apart goedgekeurde registrar werkelijk writes nodig heeft.

## Verplichte negatieve tests voor implementatie

- Onbekend workflowpad, event, branch, release-SHA, run-ID, release-ID, job of bewijsartifact: stop vóór netwerktoegang tot OVH.
- Verschillende remote of bronhash, ABSENT, symlink/non-regular/te groot bestand, ongeldige hostkey, verkeerde remote root: stop/geen groen bewijs.
- Dirty checkout, untracked bestanden, `HEAD` anders dan de oorspronkelijke releasecommit: stop vóór SFTP.
- Niet geverifieerde initiator, gebruikte deployment-credential zonder expliciete goedkeuring, ontbrekende read-only accountrechten, ontbrekend/gewijzigd GitHub-bewijs: geen uitvoering.
- Geen GitHub-comment, acceptatie, rollback of deployment bij een geslaagde of mislukte readback.
- Herhaalde verify-run mag geen acceptatie wijzigen of dubbele registraties maken.

## Go/no-go voor uitvoerende workflow

**NO-GO** totdat de exacte workflowdiff, onafhankelijke SFTP-accountrechten, trustmodel voor geauthenticeerde trigger en bewijsherkomst, negatieve tests, actionlint, drie toepasselijke CI-checks, een afzonderlijke veiligheidsreview én expliciete exacte-head merge-/activatieautorisatie zijn beoordeeld.

Dit document verleent geen nieuwe rechten en maakt geen uitvoerbare workflow aan.
