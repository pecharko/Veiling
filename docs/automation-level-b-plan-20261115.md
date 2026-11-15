# VeilingDeploy niveau B — veilige automatisering (voorstel, geen productieautorisatie)

## Huidige grens (gecontroleerd op main 2026-11-15)
- Ontwikkeling: Codex in een actieve sessie, lokale Docker/Playwright waar beschikbaar en een gezamenlijke PR.
- CI-feedback: `.github/workflows/development-ci-feedback.yml` is read-only en geeft advies. Het repareert nooit zelfstandig code na een beëindigde chat.
- Release: `.github/workflows/verified-deploy-controller.yml` blijft de bestaande owner-bound dispatchroute. `scripts/shared_ovh_release.py` bewaakt de SHA-256-manifesten, gepinde SFTP-hostkey, actuele drift, back-up, rollback en uploaderreadback.
- GitHub-hosted HTTP-tests kunnen door de OVH WAF 403 terugkrijgen. Dat is **geen** browsersucces en evenmin bewijs van onbeschikbaarheid.
- Bestaande `deployment-evidence.json` bevat uploaderreadback, niet onafhankelijke OVH-readback; `browser_acceptance` staat na uitrol op PENDING.

## Wat deze eerste veilige implementatie toevoegt
De nieuwe workflow `post-release-evidence-status.yml` reageert op voltooiing van de bestaande shared-OVH workflow én de afzonderlijke beschermde snapshot-v8-release. Via een beperkt `actions: read` token leest hij de run en de lijst met artifacts. Per releasetype controleert het script de exact verwachte, niet-verlopen evidence- en rollbackartifactnamen, zodat snapshot-v8-bewijs niet met shared-OVH-bewijs kan worden verward. Het script `release_b_status.py` maakt een compacte Actions Summary met exacte run/SHA, status en ontbrekende bewijsstappen. Dit is nadrukkelijk **geen** verificatie van de artifactinhoud of van de onafhankelijke live server. De summary krijgt altijd `independent_ovh_readback=PENDING`, `browser_acceptance=PENDING` en `release_administration=OPEN`, ook bij succesvolle workflow. Onvolledige/ongeldige API-invoer faalt gesloten. Geen secrets, SFTP, uploads, DB, workflow_dispatch, issuecommentaar of releasecontrollerwijziging.

De extra offline checker `scripts/release_b_artifact_contract.py` vergelijkt de **inhoud** van het deploy-evidenceartifact met de immutable GitHub-run (exacte repository, workflowpad, run-ID en commit), de expliciet opgegeven release-ID en het bijbehorende releasemanifest (exacte paden, bron- en remote-baselinehashes). Dit levert uitsluitend `UPLOADER_ARTIFACT_CONTENT_CONSISTENT_ONLY` op. De aanroeper moet de herkomst en authenticiteit van het manifest, de run én het artifact nog afzonderlijk bewijzen. De checker doet geen live SFTP-readback en verifieert geen browsergedrag. Zijn resultaat mag nooit automatisch een release afsluiten. De huidige GitHub-workflow haalt bewust nog geen onbetrouwbare artifact-ZIP op en voert die niet uit.

## B-vervolg dat een afzonderlijke review vereist

1. **Ontwikkeling / CI:** behoud een single-PR met source + tests + (waar passend) manifest. Automatiseer alleen de rapportage/diagnose. Een persistente autonome Codex-writer vereist een afzonderlijk ontwerp voor identiteit, werkboomisolatie, file-allowlist, rechten, audit, idempotentie en stop bij ambigue writes. Maak de adviser niet stilzwijgend een writer.
2. **Releasecontroller:** gebruik uitsluitend de bestaande owner-verificatie en specifieke finale toestemming. Een interfaceknop kan een exacte autorisatietekst samenstellen, maar nooit zelf een producerende API aanroepen. Niet algemeen autoriseren via `go`.
3. **Browseracceptatie:** lokale Playwright-preview met fictieve data waar mogelijk. Voor publiek live HTTPS alleen een vertrouwde echte client buiten GitHub-hosted WAF-blokkades. Privéadmin alleen met afzonderlijk beveiligde, niet-gepubliceerde sessie. Publiceer nooit cookies, HTML met persoonsgegevens of screenshots van admin naar publieke artifacts. De werkelijke productieacceptatie moet releasegebonden zijn.
4. **OVH-readback:** bouw een afzonderlijke **read-only** uitvoerder met apart bewijs van oorsprong, onafhankelijke identiteit, exacte release-id/main SHA, gepinde hostkey, exact bestandsmanifest en hashes. Het uitvoerresultaat moet herleidbaar zijn tot de bron; een uploaderlog of artifactnaam telt nooit als onafhankelijke meting. Geen productiecredentials toevoegen aan PR-code of onbeveiligde runner.
5. **Releaseadministratie:** maak pas na gevalideerde deploymentartifactinhoud, onafhankelijke readback en browseracceptatie een formeel acceptatieverslag. Blijft een controle ontbreken, laat de status `OPEN/PENDING`. Implementeer geen impliciete issue/PR-writes op een privileged `workflow_run` event.

## Acceptatiecriteria
- Run-ID, SHA, trigger en bronworkflow worden gevalideerd; geen conclusies uit een PR-run of vreemde workflow.
- Succesvolle workflow zonder rollback- en evidenceartifact wordt als onvolledig gerapporteerd.
- Zowel de standaard shared-OVH als de beschermde snapshot-v8-workflow worden zonder nieuwe deploymentbevoegdheden herkend; artifacts van een andere workflow tellen niet.
- Een groene job of beschikbare artifactnamen promoveert geen release tot VERIFIED of ACCEPTED.
- Ongeldige of verlopen gegevens stoppen zichtbaar; geen automatische retry van writes.
- De bestaande owner-only merge/productiegates, snapshots/restore, cron, DB, secrets en hostkey-/rollbackchecks blijven onaangeroerd.

**Deze PR verandert geen productieruntime.** Voor beschermde `.github/workflows/` en `scripts/` blijft een afzonderlijke exacte mergebeslissing vereist. Deze rapportage vervangt geen bestaande verplichte gates.

## Veilige tweede fase — GitHub-artifactinhoud controleren

De read-only `workflow_run` controleert nu na een bestaande productie-release eerst de precieze GitHub-run (repo, workflowbestand, trigger, SHA en run-ID), zoekt vervolgens de **unieke, niet-verlopen, run-gebonden** evidenceartifact en haalt die uitsluitend via een geauthenticeerde GitHub API GET op. Een nieuwe helper `scripts/release_b_zip_review.py` inspecteert de ZIP zonder uitpakken op schijf of uitvoering van inhoud, met strikte limieten voor ZIP-grootte, aantal bestanden, naam, JSON-grootte en compressieverhouding.

Het bijbehorende `releases/<release-id>.json` wordt uit GitHub opgehaald op de **exacte immutable run-head-SHA**, niet uit de huidige `main` en nooit uit een PR. De bestaande inhoudsvalidator vergelijkt die manifesthashes met het evidence-JSON. De rapportage blijft `UPLOADER_ARTIFACT_CONTENT_CONSISTENT_ONLY`; `independent_ovh_readback=PENDING` en `browser_acceptance=PENDING` blijven open. De workflow heeft alleen `contents: read` en `actions: read`, geen muterende API, geen `workflow_dispatch` en geen netwerkcontact met OVH.

**Belangrijke grens:** API-authenticatie bewijst welke GitHub-artifactbytes en welke manifestversie zijn opgehaald, niet dat de deployment echt succesvol was op de onafhankelijke OVH-server. De ZIP wordt nooit als vertrouwde code uitgevoerd. Oude releases zonder de vereiste artifact/manifestmetadata blijven onvolledig en krijgen geen automatische acceptatie. De workflow is alleen actief na een later afzonderlijk geautoriseerde release; het mergen ervan voert geen deployment uit.
