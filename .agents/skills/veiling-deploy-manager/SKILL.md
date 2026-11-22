---
name: veiling-deploy-manager
description: "Gebruik Codex als standaard ontwikkelmotor voor pecharko/Veiling en 41lokeren.be: onderzoek, implementatie, tests, PR-voorbereiding en CI-herstel. Beheer daarna review, afzonderlijk geautoriseerde merges, read-only releasevoorbereiding en gecontroleerde productie-uitrol zonder onnodige go-vragen."
---

# Veiling Deploy Manager — Codex als standaard ontwikkelmotor

Werk in het Nederlands. Repository `pecharko/Veiling`; productie `https://41lokeren.be`. De aangesloten GitHub-tool is de bron voor actuele repository- en CI-bewijzen. Historische SHAs zijn nooit actuele autorisaties.

## Codex eerst — standaard werkwijze

**Benut de actieve Codex-omgeving voor al het toegestane ontwikkelwerk.** Veiling Deploy Manager bepaalt scope, bewijs en autorisatiegrenzen; Codex onderzoekt, implementeert, test, maakt de reviewbare PR gereed en herstelt CI binnen die scope. Als dit gesprek al in Codex draait, voer het werk hier uit: geen tweede agent, aparte codevoorstellenmotor of fictieve Codex-dispatch nodig. Als Codex niet beschikbaar is, gebruik de aanwezige ontwikkeltools en benoem de beperking; claim geen externe taakstart of achtergrondwerk.

- Een opdracht zoals `doe verder` of `werk deze feature uit` dekt veilige ontwikkeling tot de gevraagde acceptatiecriteria zijn afgewerkt: relevante bron lezen, lokale edits, geïsoleerde tests, developmentbranch/PR en CI-herstel binnen de bestaande autorisatie. Toestemming voor productie, rechten, secrets of merges ontstaat daar niet uit.
- Lees actuele `AGENTS.md`, requirements, bestaande PR en deze skill. Gebruik een geïsoleerde developmentbranch/worktree, behoud andermans wijzigingen en toets overlappende bestanden. De feature-opdracht is geen toestemming om repository-instructies, controllerrechten of gates te versoepelen.
- Maak vooraf één lijst met gewenste eindfunctionaliteit en acceptatiecriteria. Bouw vervolgens de benodigde samenhangende wijziging. Een helper, basisfase, groene deeltest of draft PR is geen eindpunt zolang ander veilig ontwikkelwerk binnen scope resteert.
- **Een toekomstige merge- of productiegrens blokkeert geen voorafgaand veilig codeerwerk.** Ook wijzigingen aan `private/`, schema, cron en snapshotcode mogen als voorstel met geïsoleerde tests worden voorbereid wanneer die ontwikkeling gevraagd is. Uitvoering op productie en afzonderlijk vereiste merges blijven geblokkeerd. Als er echt een ontbrekend productbesluit, externe afhankelijkheid of toolgrens is: werk onafhankelijke onderdelen af en benoem exact de blokkade.
- Gebruik bestaande componenten en de gewone Codex-naar-PR-werkwijze. PR #483 is op verzoek van de eigenaar gesloten als achterhaald op 31 oktober 2026; controleer actuele status als dat relevant wordt. Herstart die codevoorstellenmotor of de afgesloten Phase-C-writer niet als voorwaarde om met Codex te ontwikkelen.
- Pas vóór elke merge de **actuele** repositoryregels en bestaande expliciete eigenaarautorisatie toe. De huidige `AGENTS.md` vereist een afzonderlijke mergebeslissing; een normale Codex-opdracht of groen CI vervangt die niet. Is een grens bereikt, vraag alleen het ontbrekende concrete besluit en houd eventuele overige veilige ontwikkeling gaande.
- Lees voor uitvoering en overdracht [de Codex-werkwijze](references/codex-development.md). Lees historische Phase-C- en release-details alleen wanneer de actuele taak ze nodig heeft. Deze skill voegt geen technische rechten, agentservice of workflow toe.

## Gewone website-uitrol — één PR, twee eigenaarbeslissingen

De eigenaar is de enige deployer en rolt serieel uit. Voor een gewone allowlisted websitewijziging met opdracht `deploy` is de standaard **één PR met code, relevante tests en het releasemanifest**, gevolgd door **één exacte mergeautorisatie en één afzonderlijke exacte productieautorisatie**. Codex verzorgt de technische tussenstappen. Gebruik bestaande logs en artefacten, bundel onafhankelijke controles en meld alleen betekenisvolle voortgang, een concreet probleem of de eerstvolgende eigenaarbeslissing. Vraag niet naar andere deployers, runnummers, screenshots of tussentijds `go` als de beschikbare bronnen volstaan. Ontwikkel-PR's en CI kunnen parallel lopen, dus controleer actuele GitHub-refs en feitelijke overlap.

## Hoofdregel — doorwerken tot een echte autorisatiegrens

**Na een opdracht om een wijziging volledig voor te bereiden, voer in dezelfde actieve sessie alle veilige, toegestane GitHub- en read-only OVH-stappen uit tot de eerste echte autorisatiegrens.** Stop niet na PR, groen CI-resultaat, merge, release-request, manifest, preflight of main-push-CI en vraag daarvoor geen extra `go`. Geef tijdens langere werkzaamheden korte voortgangsupdates. Verifieer na een expliciet geautoriseerde deployment zelfstandig de run, zes of zoveel als geautoriseerde readback-hashes en de evidence/rollback-artifacts; vraag niet standaard `groen`. **Geen productie-mutatie zonder afzonderlijke exacte toestemming**; immutable commit, goedgekeurde SHA-256-baselines, driftcontrole, codeback-up, rollback en post-uploadreadback blijven vereist. Bij ontbrekende dispatch-tool geef één handmatige startinstructie. Claim geen achtergrondwerk of acceptatie zonder bewijs.

## Verplichte uitvoeringslus — geen rituele `go` (geleerd uit PR #397–#401)

Behandel `queued`/`in_progress` CI, een net geopende PR, een geslaagde merge, een nieuwe read-only branch en een nog niet zichtbaar manifest als **tussenstanden**, nooit automatisch als eindpunt van je antwoord. Controleer herhaaldelijk maar begrensd, inspecteer concrete jobs, herstel veilige testfouten en voer intussen andere toegestane stappen uit. Zodra een stap is afgewerkt, **voer de volgende veilige stap meteen uit**, zonder nieuwe gebruikersboodschap af te wachten. Stop alleen bij een echte goedkeuringsgrens, aantoonbare blokkade of sessie-/toolgrens; zend dan geen uitnodiging tot `go` maar de concrete ontbrekende actie/status. Gebruik `references/doorlopende-release-uitvoering.md` als beslisboom en toets aan de cronlog-keten PR #397–#401. **Dezelfde bestaande beveiligingscontroles blijven volledig van kracht.**

## Drie gescheiden automatiseringsbanen — verplicht classificeren

1. **Ontwikkeling (actieve Codex-uitvoering, of beschikbare ontwikkeltools):** een gewone feature-opdracht dekt implementatie, tests, PR, begrensd CI-pollen, herstel en alle veilige vervolgstappen binnen dezelfde actieve beurt. Maak vóór edits een lijst met acceptatiecriteria en werk alle bekende checks in één coherente batch af. Stop niet na één micro-commit, noch om de gebruiker een nieuw `go` te ontlokken. CI-start is geen eindresultaat. Eindig alleen bij echte goedkeuringsgrens, bewezen blokkade of sessie/toolgrens; benoem dan concreet wat nog niet af is.
2. **CI-feedback (asynchrone GitHub Actions):** workflow `development-ci-feedback.yml` mag alleen run/jobstatus lezen en een Actions-summary schrijven. Lees de logs en herstel regressies wanneer de actieve sessie loopt. GitHub Actions hervat niet automatisch een afgesloten ChatGPT-beurt; claim nooit dat CI zelfstandig nieuwe code schrijft of dat de assistant later in de achtergrond verdergaat.
3. **Productieautorisatie (bestaande releaseworkflow):** uitsluitend de bestaande geautoriseerde dispatchroute en releasegates voeren een uitrol uit. CI groen, feedbacksummary en `go` geven geen DB-, schema-, cron- of OVH-schrijfbevoegdheid. Geen nieuwe CI-trigger naar de deploycontroller en geen versoepeling van de owner-authenticatie.

Lees bij automatiseringsverzoeken `references/automation-lanes.md`. Houd de drie banen apart in elk statusbericht en maak onderscheid tussen *voorbereid*, *CI bevestigd*, *merge bevoegd* en *productie geautoriseerd*.

## Fase C — technisch afgesloten, niet algemeen geactiveerd (31 oktober 2026)

- De **eenmalige, strikt gescopeerde Phase-C NEW-sandboxproef** is technisch aanvaard via onafhankelijk GitHub-bewijs, gedocumenteerd in het **gesloten** issue [#482](https://github.com/pecharko/Veiling/issues/482#issuecomment-6540160576). Dit bewijst **niet** dat een algemene autonome codecorrector of een productie-writer is geactiveerd. PR #475 en #426 bleven draft en ongemergd. Alle hier genoemde commits zijn **historische bewijsankers**, nooit herbruikbare autorisaties.
- **Ambigue mutatie-uitkomst:** bij `AMBIGUOUS_STOP_NO_RETRY`, time-out, ontbrekende receipt of `executed=true`: **stop definitief; nooit dezelfde attempt, nonce, issue, comment of tokenopdracht opnieuw uitvoeren.** Lees onafhankelijk de feitelijke GitHub-refs, commit-parent, volledige eenbestandsdiff, blob/digest, PR-status en toepasselijke CI. Registreer **apart** de lokale writerrespons en de onafhankelijke eindtoestand: `READBACK_CONFIRMED` is niet vereist om een daadwerkelijk bewezen remote eindtoestand te rapporteren, maar een groene remote readback mag de ambigue lokale receipt **nooit wissen**. Een mislukte preflight *vóór* de uitvoerder is iets anders dan een ambigue uitgevoerde POST; neem nooit zonder bewijs aan dat geen schrijfpoging plaatsvond.
- **Minimale App-rechten:** houd owner-verificatie van beide volledige rulesets (`bypass_actors: []`) gescheiden van de daadwerkelijke beperkte writer-verificatie. GitHub REST kan `bypass_actors` weglaten bij tokens zonder ruleset-schrijfrechten; accepteer alleen die gedocumenteerde **weglating bij de writer** nadat een verse eigenaarcontrole leeg-bypass bewees. `null`, zichtbare bypass, ontbrekende `current_user_can_bypass=never`, 403 of drift blijven `DENY`. Geen extra rechten of secrets om ontbrekende velden te omzeilen.
- **Autorisatie blijft eenmalig en exact:** een nieuw eigenaar-issue, een afzonderlijk authentiek eigenaarcommentaar gekoppeld aan de geverifieerde worker-commit, beperkte installatie-identiteit, een nieuwe ongebruikte nonce, de precieze ref-/bestandsallowlist en een nieuwe expliciete one-shot-toestemming zijn noodzakelijk vóór een toekomstige schrijfpoging. Geen automatische herstart of algemene writeractivering na een geslaagde sandboxproef. Vraag geen autorisatie voor uitsluitend read-only controles, CI-verificatie of verslaggeving wanneer die al in scope zijn.

Lees bij latere fase-C-vragen **`references/phase-c-acceptatie-en-ambigue-write.md`** voor het historische bewijs, de onherroepelijk gebruikte nonce en de scheiding tussen lokale respons, remote toestand en toestemming. De volledige bestaande OVH-, DB-, productie- en releasegates blijven ongewijzigd.

## Goedkeuringen via checkbox — standaardgebruikersinterface

**Toon bij een echte expliciete autorisatiegrens standaard één compacte checkbox met een bevestigingsknop in plaats van een lange tekst die de gebruiker moet kopiëren.** Gebruik de beschikbare interactieve chatcomponenten; gebruik een tekstalternatief uitsluitend als checkbox of knop niet beschikbaar is. Laat PR/issue, exacte immutable head-SHA, gecontroleerde bestandscope, aard van de actie en uitsluitingen zichtbaar zien. Voor productie ook de afzonderlijke remote SHA-256/ABSENT-baselines, driftcheck, backup/rollback, release-ID en hoofdcommit; beperk de inhoud nooit om de checkbox kort te houden.

- Checkbox is standaard **niet aangevinkt**. Bevestigingsknop is uitgeschakeld tot aanvinken. Aanvinken alleen is **geen** toestemming en start **geen** actie.
- De bevestigingsknop stuurt een **expliciet gescopeerde autorisatieboodschap als nieuwe chatbeurt** (indien ondersteund via `GenUI.issueNewTurn`); hij mag niet rechtstreeks de GitHub-merge, workflowdispatch, OVH- of databasewrite uitvoeren. Verifieer in die nieuwe beurt alle actuele voorwaarden opnieuw; geen blind vertrouwen op oude status.
- Leg bij de knop exact de bedoelde actie, PR, commit, paden en uitsluitingen in de autorisatietekst vast. Een algemeen `groen`, `go`, aanvinken of rapportage is nooit impliciete risicovolle toestemming.
- Hergebruik één controlevak voor één samenhangende beslissing, zonder onnodig formulier of meerdere stappen. Toon geen checkbox voor acties die zonder toestemming veilig automatisch kunnen doorgaan.
- Bij ontbrekende UI-interactie: toon één korte, volledig ingevulde toestemmingszin als fallback. Bij drift of veranderde commit vervalt de eerdere goedkeuring en moet de checkbox met nieuwe gegevens opnieuw verschijnen.

## De standaard releaseketen (één doorlopende uitvoering)

1. **Bereid één PR voor.** Controleer actuele `main`, diff, afhankelijkheden en overlappende PR's. Implementeer en test op een aparte branch. Classificeer elk runtimepad met de bestaande allow/deny-gates in `scripts/shared_ovh_readonly_baselines.py` en `scripts/shared_ovh_release.py`. Beschermde of onbekende paden volgen de bestaande gespecialiseerde route. Auth, database, cron, snapshots, secrets en releasegates houden hun eigen grenzen.
2. **Voeg het manifest vóór de mergebeslissing toe.** Hergebruik per bestand de laatste geauthenticeerde `VERIFIED`-productiereadback nadat je de run, commit, logs en beschikbare evidence/rollback-artefacten hebt gekoppeld en op latere schrijfacties hebt gecontroleerd. Gebruik een bewezen read-only meting voor `ABSENT`. Bij onzeker of ontbrekend bewijs is een verse gepinde read-only baseline nodig, met afzonderlijke toestemming als de workflow of OVH-toegang die vereist. Verifieer de exacte bestandsinputs en resultaatregels. Bereken de bronhashes op de kandidaat, valideer `releases/<id>.json` en voer CI uit op de finale PR-head. Gebruik een losse manifest-PR alleen met een concrete reden, bijvoorbeeld als de code al gemerged is. Maak geen extra release-request voor een gewone wijziging met bewezen baselines.
3. **Vraag één exacte mergebeslissing.** Zet de PR klaar en toon nummer, mergewijze, head-SHA, bestandscope en CI-resultaat. Een opdracht `deploy`, groen CI of ontwerpacceptatie autoriseert geen merge. Na toestemming verifieer je de actuele head en merge je met de verwachte SHA. Volg meteen de geverifieerde nieuwe `main`-commit en de checks van het afzonderlijke push-event. Controleer manifest, bronhashes en releasevolgorde op die commit.
4. **Vraag één afzonderlijke productieautorisatie.** Toon release-ID, exacte actuele `main`-SHA, manifest, runtimepaden met bron- en remote-hashes, pre-write driftcheck, back-up/rollback en readback. Respecteer een opdracht om alleen voor te bereiden of nog niet uit te rollen. Vraag alleen het nog ontbrekende besluit.
5. **Start en verifieer de reeds geautoriseerde uitrol.** Controleer `main` en manifest opnieuw. Gebruik eenmaal de bestaande gated workflow via een bevoegd middel, bijvoorbeeld GitHub CLI wanneer beschikbaar, of de bestaande issuecommentcontroller met bewezen eigenaar-issue, label en eigenaarcomment. Geef alleen bij ontbrekende dispatchmogelijkheden één Actions-link met exact release-ID en `DEPLOY-<id>-<main-sha>`. Stop bij SHA- of remote-drift. Bij een ambigue write: geen retry, verifieer de server- en GitHub-eindtoestand read-only. Controleer na dispatch de exacte run, driftcheck, back-up, rollback-artefact en per-file readback. Onderscheid uploader-readback, onafhankelijke technische bewijscontrole en functionele browseracceptatie. Vraag functionele acceptatie alleen als die nog ontbreekt.

## Lokale testomgeving na een geslaagde website-uitrol

- Vraag in de eindmelding van een technisch geverifieerde website-uitrol één keer: **“Wil je dat ik de lokale testomgeving synchroniseer met deze release?”** Zet de vraag bij de uitrolbevestiging en de eventueel nog ontbrekende browseracceptatie. Sla de vraag over als de gebruiker synchronisatie al heeft gevraagd, afgewezen of als de preview al op de uitrolcommit staat. Stel ze niet bij een mislukte, ambigue of teruggedraaide uitrol.
- Synchroniseer alleen op verzoek. Gebruik de geverifieerde uitrolcommit als bron voor een aparte preview-worktree met fictieve data. Laat de huidige werkbranch en lokale wijzigingen intact. Controleer de preview-worktree en Docker-mount met `sh scripts/preview diagnose` waar die workflow van toepassing is. Rapporteer de werkelijke bron-SHA en lokale URL zodra de preview werkt. Volg [de lokale previewwerkwijze](../../../docs/local-ui-preview.md).

## Veiligheidsgrenzen (gaan altijd voor snelheid)

- Productie-SFTP, DB/schema-/datawrites, snapshotrestore of -reset, verwijderingen, archivering/cronactivatie, secrets, rechten en releasegatewijzigingen vereisen afzonderlijke, specifiek gescopeerde toestemming. Voor DB-wijzigingen vraag één keer of oude snapshots na upgrade herstelbaar moeten blijven (`ja/nee/onbekend`); leg de keuze vast en blijf bij onbekend niet destructief verdergaan.
- CI moet op **actuele PR-head** en vervolgens `main` gecontroleerd worden. Testresultaten zijn niet uitwisselbaar met alleen een groene workflowstatus; inspecteer concrete toepasselijke jobs en regressietests.
- Hostkeypinning, bestandsallowlist, per-file remote SHA/ABSENT, driftcheck onmiddellijk voor write, codebackup, rollback en readback zijn niet optioneel. Voor de actuele toegestane workflow gelden de repositorycontracten, niet historische voorbeeldcommits.
- OVH Application Firewall kan GitHub HTTP-smoketests met 403 blokkeren. 403 is geen succesvolle websitecontrole, maar ook geen bewijs van publieke uitval. Niet omzeilen of gates verzwakken; vraag afzonderlijke browsercontrole waar nodig.
- Parallel werk: check overlappende paden, afhankelijkheden, actuele main SHA en serverbaselines. Andere commits kunnen de exacte SHA-gate ongeldig maken, ook zonder padconflict; dan nieuwe autorisatie nodig.
- Bij tool-/tijdslimiet rapporteer bewijsstatus en één concrete volgende actie; claim nooit later in de achtergrond door te werken.

## Raadpleeg alleen relevante details

- `references/codex-development.md`: Codex als ontwikkelmotor, acceptatiecriteria, lokale tests, CI-herstel, exacte overdracht en echte stopgrenzen.

- `references/release-workflow.md`: routering, CI-impact, volledige shared-OVH-releaseflow en bekende manifest-PR-fallback.
- `references/security-and-approvals.md`: strengere uitzonderingen, single-deployer, WAF, bewijs/acceptatie.
- `references/github-controller.md` en `references/shared-manifest-controller.md`: bestaande GitHub-native issuecommentcontroller, fase A/B, artifactbewijs en strikte owner-auth.
- `references/parallel-workflows.md`: multi-PR-drift en overlap.
- `references/phase-c-acceptatie-en-ambigue-write.md`: afgesloten fase-C-sandboxproef, owner/writer-rulesets, eenmalig mutatiebewijs en ambigue lokale receipt.
- `references/database-and-snapshots.md`: migraties, snapshotcompatibiliteit, cron/archivering.
- `references/historische-instructies-20261028.md`: volledige onveranderde oude skill voor audit, alleen bij twijfel over historische afspraken; geen nieuwe releaseautorisatie uit dit archief afleiden.

## Toets aan vijf terugkerende scenario's

1. UI/CSS: code, tests en manifest op één finale PR-head. Hergebruik bewezen baselines vóór de merge, verifieer push-CI erna en vraag pas dan de afzonderlijke productieautorisatie.
2. Securityfix: aparte review/mergegoedkeuring waar vereist; daarna zonder extra `go` voorbereiden, nooit stilzwijgend deployen.
3. Databasewijziging: snapshotcompatibiliteit vooraf, aparte muterende autorisatie en herstelplan.
4. Parallelle releases: concrete pad-/SHA-drift toetsen; onafhankelijke voorbereiding mag doorgaan, geautoriseerde SHA nooit vervangen.
5. Remote drift: vóór write stoppen; bewijs, rollback-/incidentstatus beoordelen en om nieuwe specifieke autorisatie vragen.
