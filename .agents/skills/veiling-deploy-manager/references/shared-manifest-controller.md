# Shared OVH manifest controller: exacte autorisatie en read-only verificatie

## Toepassingsgebied en actuele status

- PR #357: gemerged op GitHub `main` als `007a77e752523ce3758a215e5cd053cc8353ec34`, head `7bda95382349286f0b2ad6cfc717f22d732d9f67`. Echte push-CI `40726465111`: success; dit is geen toekomstig releasebaseline. Controleer altijd actuele `main`.
- Bestaand pad: `.github/workflows/verified-deploy-controller.yml`, `scripts/verified_deploy_controller.py`, `scripts/shared_manifest_controller_adapter.py`, `.github/workflows/shared-ovh-release.yml`, `scripts/shared_ovh_release.py`.
- GitHub-hosted `issue_comment`-workflow doet de one-shot dispatch **alleen** vanuit een open, nooit eerder gesloten, door de eigenaar gemaakt GitHub-issue met titelprefix `DEPLOY-AUTH:` en label `approved-deploy-request`, en alleen als de **repository-eigenaar** het comment plaatst. De ChatGPT-GitHub-connector kan een bot/ander actor gebruiken: neem niet aan dat een door de connector geplaatst comment voldoet aan de eigenaarcheck. Maak geen eigenaarsidentiteit na.
- Het autorisatiecomment moet volledig en exact zijn. De tekst voor een concrete release bevat de werkelijke hashes, nooit voorbeeldhashes:

```text
DEPLOY SHARED MANIFEST RELEASE
release_id=<exacte-id-uit-releases-json>
release_sha=<40-hex-van-actuele-main>
file=<eerste-runtimepad>|<64-hex-source-sha256>|<64-hex-remote-sha256-of-ABSENT>
file=<tweede-runtimepad>|<64-hex-source-sha256>|<64-hex-remote-sha256-of-ABSENT>
```

- De bestandsregels komen **in identieke volgorde** uit `releases/<release_id>.json` op de goedgekeurde actuele `main`. Gebruik exact `path`, `source_sha256`, `remote_sha256`. De adapter hergebruikt de validatie van het gedeelde engine-manifest voor toegestane runtimepaden, niet-lege bronnen en SHA-256. Beschermde paden (`private/snapshots.php`, `private/visitor_stats_daily_cron.php`) blijven verboden, ook met een geldige hash.
- Er mag maar **één** expliciete eigenaarcomment zijn per goed te keuren release; bij duplicatie of ongeldige/replay-gebruiken fail-closed. De controller controleert checkout == actuele `main`, hercontroleert `main` voor sluiting, sluit approval-issue om replay te verhinderen en dispatcht alleen de vaste gedeelde workflow met `release_id` en `confirmation=DEPLOY-<id>-<sha>`.
- Is er geen reeds bestaand eigenaar-issue dat exact voldoet, voorkom onnodige handmatige aanmaak plus comment: begeleid zo nodig de **handmatige** `shared-ovh-release.yml`-dispatch met deze twee velden. De connector in ChatGPT kan geen eigen `workflow_dispatch` doen. De live uploader blijft de source-/baselinevergelijking, pinned SSH-hostkey, driftcheck onmiddellijk voor SFTP, backup, rollback en per-bestand readback vereisen.

## Zonder `go`: verifiëren van GitHub-bewijs

1. Verifieer actuele `main`, release-ID en expliciete toestemming. Open alleen een run waarvan release/SHA/workflow aantoonbaar overeenkomen; geen dispatch enkel om een run-ID te verkrijgen.
2. Gebruik read-only GitHub connector GET om ook push-CI en handmatige Actions-runs te vinden:
   `mcp__GitHub__fetch({url:"https://api.github.com/repos/pecharko/Veiling/actions/runs?head_sha=<sha>&per_page=100"})`. Controleer expliciet `event` (`push`, `workflow_dispatch`), `workflow_id`/`path` of `name`, `status`, `conclusion`, `head_sha`, run-ID en volledige paginering. Indien een run niet beschikbaar is, meld dit eerlijk.
3. Gebruik `mcp__GitHub__fetch_workflow_run_jobs` en `mcp__GitHub__fetch_workflow_run_artifacts` (en toegestane artifact-read) op de **gevonden** run-ID. Controleer de exacte bestanden, bron- en readback-hashes, status van rollback, en aanwezigheid van evidence- en rollback-artifacts (standaard 7 dagen). Hergebruik bestaande offline reconciliatiemodules en het evidencecontract; geen tweede checker bouwen.
4. Scheid `CI_GREEN`, `MERGED`, `REPORT_VERIFIED`, `REMOTE_VERIFIED`, `BROWSER_ACCEPTED` en `PRODUCTION_ACCEPTED`. Geslaagde uploadjob met evidence is geen onafhankelijk OVH-readback of browseracceptatie. Vraag waar relevant nog één gerichte gebruikersacceptatie, en leg het resultaat **eenmaal** in het bestaande issue/PR vast.
5. Na fout **niet automatisch opnieuw dispatchen**: een geconsumeerd approval-issue wordt nooit hergebruikt. Onderzoek de werkelijk gestarte workflow, serverdrift en rollback. Een volgende write vereist opnieuw specifieke toestemming.

## Parallelle taken

- Veranderingen op `main` in parallel zijn op zichzelf geen conflict. Vergelijk gewijzigde paden, release-allowlist en afhankelijkheden; blokkeer uitsluitend echte overlap of een inmiddels ongeldige immutable autorisatie-SHA.
- Alleen expliciete productieautorisatie kan een productie-release triggeren. `go`, CI-success, een gemergede PR, een read-only preflight en een skill-update zijn **nooit** zelfstandige OVH-autorisaties.
- Vermijd extra gebruikersinteracties bij zuiver read-only vervolgwerk; blijf tijdens actieve sessie zelfstandig doorlopen tot toestemming, echte blokkade of eindresultaat. Zonder automatisering bestaat geen achtergrondbewaking buiten de sessie.
