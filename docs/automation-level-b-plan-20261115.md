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

## Veilige derde fase — extern bewijs, uitsluitend offline contract

`scripts/release_b_independent_contract.py` controleert het **formaat en de samenhang van aangeleverde bewijsclaims** voor uploader, een afzonderlijk read-only SFTP-meetproces en een vertrouwde externe browser. Het script weigert onvolledige, onveilige of dubbele bestandslijsten, afwijkende SHA-256-hashes, verschillen in release-ID, main-commit en deployment-run, ontbrekende browserchecks en mislukte browserresultaten.

**Cruciaal:** een `origin`-label in JSON is uitsluitend een claim, **geen authenticatie** van de uitvoerder. Een overeenkomende hash in twee door dezelfde actor aangeleverde documenten is evenmin onafhankelijke OVH-readback. Zelfs bij drie onderling consistente claims blijft `source_authenticity=NOT_ESTABLISHED_BY_THIS_CONTRACT`, `independent_origin_authenticated=false`, `browser_origin_authenticated=false` en `release_administration=OPEN`. Het script kan geen releasedossier sluiten en heeft geen netwerk- of schrijffuncties.

Voor latere activering zijn aparte identiteiten en vooraf gedefinieerde externe attestatie vereist: gepinde SFTP-hostkey, een read-only credential dat niet door de uploader wordt gedeeld, exacte remote paden en hashes uit één immutable manifest, tijdige metingen, een afzonderlijk browserprofiel met vooraf vastgelegde functionele checks, en onafhankelijk verifieerbare ondertekening of betrouwbare Actions-evidence van beide bronnen. Geen publiek admin-cookie, screenshot, private backup of persoonlijke data in logs/artifacts. De uiteindelijke acceptatie vereist altijd een bewezen herkomst van álle bewijsstukken en de bestaande eigenaar-/releasegates. Een JSON-document met `status=PASS` kan dit nooit op zichzelf autoriseren.

Deze fase voegt bewust **geen** automatisch SFTP-verzoek, browseraanmelding, privileged `workflow_run` write, nieuwe secret of productieacties toe. De wijziging van de bestaande workflow blijft beperkt tot PR-geïsoleerde offline tests.

## Fase 4 — losstaande collectors voorbereid (niet geactiveerd)

Er zijn twee expliciet **losstaande** uitvoerders voorbereid. `scripts/release_b_collect_readonly.py` leest uitsluitend met een apart `OVH_READONLY_SFTP_*`-account en vastgepinde hostkey; er bestaat geen fallback naar uploadersecrets, geen uploadmodus en geen gedeelde deploysessie. De kern `release_b_independent_sftp.py` valideert eerst het bestaande releasebewijs, weigert onveilige paden, volgt geen symlinks in parentmappen of doelbestanden en berekent SHA-256 met begrensde alleen-lezen streams. Een fout stopt de controle. De beheerder moet **buiten GitHub PR-code** zorgen voor een aantoonbaar alleen-lezen account, afzonderlijke beheeridentiteit, vastgepinde hostkey, gecontroleerd remote root en bescherming van de attestatie. De code bewijst niet zelf dat de accountrechten echt alleen lezen zijn.

`scripts/release_b_browser_run.py` biedt een losse Playwright Chromium-start, zonder cookies/sessiehergebruik, adminlogin, screenshots of exports. `release_b_public_browser.py` controleert uitsluitend publieke catalogus en kunstenaarsindex op HTTP 200, verwachte zichtbare tekst en exact toegestane eind-URL; WAF-403, redirects en niet-zichtbare pagina's worden FAIL, nooit automatisch PASS. Een externe betrouwbare runner is nog niet geprovisioneerd. De browserclaims krijgen pas echte bewijswaarde als uitvoering, identiteit, releasebinding en integriteit onafhankelijk geauthenticeerd zijn.

**Niet geactiveerd:** geen van deze runners is opgenomen in `workflow_run`, een deployworkflow, een scheduler of een GitHub productiesessie. De bestaande workflow wordt enkel uitgebreid met offline PR-regressietests. Er is geen SFTP-verbinding of browseraanroep naar productie uitgevoerd tijdens ontwikkeling of PR-CI. Echte fase-4-operationele activering vereist afzonderlijke owner-/securityreview van read-only account, key/host pinning, runneridentiteit, netwerktoegang, scope en authenticatie van beide evidence-uitkomsten. Fase 5 mag releaseadministratie alleen sluiten wanneer die onafhankelijk authentieke bewijzen bestaan; overeenkomende zelfverklaarde JSON-velden zijn onvoldoende.

## Fase 5 — automatische statusadministratie, nog geen geauthenticeerde afsluiting

De bestaande read-only GitHub-workflow genereert voortaan aanvullend een compact dossieroverzicht met `release_b_closeout_report.py`: dezelfde run en commit, uploader/rollbackartifact aanwezig of ontbrekend, en de open onafhankelijke gates. Het overzicht is zichtbaar in de Actions Summary zonder issues, PR's, productie of bestaande statusvelden te wijzigen. De releaseadministratie blijft **OPEN** zolang onafhankelijke OVH-readback, browseracceptatie en authentieke herkomst niet werkelijk bewezen zijn. Een procedure voor ondertekende of anderszins onafhankelijk geauthenticeerde externe attestaties moet nog afzonderlijk worden ontworpen en beoordeeld voordat een release ooit automatisch `CLOSED` mag krijgen.

Deze gecombineerde fase-4/5-PR vermindert rapportagehandelingen, maar is **geen operationele activering van onafhankelijke productiecontroles**. Er blijft een expliciete eigenaarreview vereist voor nieuwe externe identities/credentials, netwerkpermissies en elke eventuele automatische afsluitroute.
