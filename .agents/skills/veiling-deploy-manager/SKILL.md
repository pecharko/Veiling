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

## Vast rolloutprofiel — één eigenaar, seriële deploys

Gebruikersinstructie van 31 oktober 2026: de gebruiker is de enige deployer en draait nooit twee deploys parallel. Ga hier altijd van uit en vraag dit niet opnieuw. Plan één seriële rollout onder diens beheer; hergebruik bewezen baselines en voeg geen afstemmingsrondes, parallelle-deployercontroles of herhaalde preflights toe voor veronderstelde gelijktijdige deployments. Ontwikkel-PRs en CI kunnen wel parallel lopen. De bestaande exacte autorisaties, SHA-/driftgates, back-up, rollback en readback blijven ongewijzigd; reageer op feitelijke wijzigingen in plaats van hypothetische parallelle deploys.

## Hoofdregel — doorwerken tot een echte autorisatiegrens

**Na een opdracht om een wijziging volledig voor te bereiden, voer in dezelfde actieve sessie alle veilige, toegestane GitHub- en read-only OVH-stappen uit tot de eerste echte autorisatiegrens.** Stop niet na PR, groen CI-resultaat, merge, release-request, manifest, preflight of main-push-CI en vraag daarvoor geen extra `go`. Geef tijdens langere werkzaamheden korte voortgangsupdates. Verifieer na een expliciet geautoriseerde deployment zelfstandig de run, zes of zoveel als geautoriseerde readback-hashes en de evidence/rollback-artifacts; vraag niet standaard `groen`. **Geen productie-mutatie zonder afzonderlijke exacte toestemming**; immutable commit, goedgekeurde SHA-256-baselines, driftcontrole, codeback-up, rollback en post-uploadreadback blijven vereist. Bij ontbrekende dispatch-tool geef één handmatige startinstructie. Claim geen achtergrondwerk of acceptatie zonder bewijs.

## Verplichte uitvoeringslus — geen rituele `go` (geleerd uit PR #397–#401)

Behandel `queued`/`in_progress` CI, een net geopende PR, een geslaagde merge, een nieuwe read-only branch en een nog niet zichtbaar manifest als **tussenstanden**, nooit automatisch als eindpunt van je antwoord. Controleer herhaaldelijk maar begrensd, inspecteer concrete jobs, herstel veilige testfouten en voer intussen andere toegestane stappen uit. Zodra een stap is afgewerkt, **voer de volgende veilige stap meteen uit**, zonder nieuwe gebruikersboodschap af te wachten. Stop alleen bij een echte goedkeuringsgrens, aantoonbare blokkade of sessie-/toolgrens; zend dan geen uitnodiging tot `go` maar de concrete ontbrekende actie/status. Gebruik `references/doorlopende-release-uitvoering.md` als beslisboom en toets aan de cronlog-keten PR #397–#401. **Dezelfde bestaande beveiligingscontroles blijven volledig van kracht.**

## Drie gescheiden automatiseringsbanen — verplicht classificeren

1. **Ontwikkeling (actieve Codex-uitvoering, of beschikbare ontwikkeltools):** een gewone feature-opdracht dekt implementatie, tests, PR, begrensd CI-pollen, herstel en alle veilige vervolgstappen binnen dezelfde actieve beurt. Maak vóór edits een lijst met acceptatiecriteria en werk alle bekende checks in één coherente batch af. Stop niet na één micro-commit, noch om de gebruiker een nieuw `go` te ontlokken. CI-start is geen eindresultaat. Eindig alleen bij echte goedkeuringsgrens, bewezen blokkade of sessie/toolgrens; benoem dan concreet wat nog niet af is.
2. **CI-feedback (asynchrone GitHub Actions):** workflow `development-ci-feedback.yml` mag alleen run/jobstatus lezen en een Actions-summary schrijven. Lees de logs en herstel regressies wanneer de actieve sessie loopt. GitHub Actions hervat niet automatisch een afgesloten ChatGPT-beurt; claim nooit dat CI zelfstandig nieuwe code schrijft of dat de assistant later in de achtergrond verdergaat.
3. **Productieautorisatie (bestaande releasecontroller):** uitsluitend de bestaande geverifieerde issuecommentcontroller en releasegates kunnen geautoriseerde deploymentdispatch doen. CI groen, feedbacksummary en `go` geven geen DB-, schema-, cron- of OVH-schrijfbevoegdheid. Geen nieuwe CI-trigger naar de deploycontroller en geen versoepeling van de owner-authenticatie.

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

1. Lees actuele `main`, vereisten, diff, afhankelijkheden en overlap met parallelle PRs. Behandel een nieuwe main-SHA niet automatisch als inhoudelijk conflict; controleer bestanden en commit-ancestry.
2. Laat Codex de kleinste **volledige** wijziging voor de gevraagde acceptatiecriteria implementeren en betekenisvolle regressietests uitvoeren. Werk de bestaande passende development-PR bij of maak er één wanneer dat binnen de opdracht valt. Verifieer exacte head/diff, veiligheidsreview en concrete toepasselijke CI-jobs; herstel regressies. Bereid alle overige veilige ontwikkeling en controles af vóór het mergevoorstel. Merge alleen na de volgens actuele AGENTS.md en bestaande autorisatie vereiste afzonderlijke mergebeslissing. Security/auth/releasegate/schema/database/restore houden hun specifieke grenzen; zo'n toekomstige grens is geen reden om eerder te stoppen met veilige voorbereiding.
3. Verifieer `main` en echte **push-CI** afzonderlijk. Bepaal exacte runtimebestanden. **Classificeer elk pad vóór `release-requests/`** via de huidige `scripts/shared_ovh_readonly_baselines.py` en `scripts/shared_ovh_release.py` allow/deny-gates, en zo beschikbaar `scripts/release_path_routing.py`. `private/snapshots*`, config/secrets en onbekende beschermde paden: fail closed, gespecialiseerd pad en afzonderlijke autorisatie. `private/visitor_stats_daily_cron.php` uitsluitend via zijn aparte issue224-cronprocedure. Houd gemengde releases gescheiden. Versoepel geen allowlist of gate.
4. Voor toegestane shared-OVH-bestanden: maak één kleine `release-requests/<id>.json`-PR, controleer CI, merge, volg de bestaande automatische **read-only** OVH-manifestworkflow, controleer werkelijk verzamelde hashes, herstel zo nodig de al gepushte manifestbranch via GitHub-connector als de bot geen PR mag openen; **geen extra SFTP-collectie** als de baselines bewezen zijn. Controleer manifest-PR, CI, merge en de nieuwe main-push-CI.
5. Verifieer vlak vóór het autorisatievoorstel: immutable actuele main SHA, exact manifest, elke source-SHA, afzonderlijke remote SHA/`ABSENT`, order/afhankelijkheden, rollback en beschikbare evidence. Lever **één** exact geformuleerde finale productieautorisatie. `go`/groen/CI/merge is nooit productieautorisatie.
6. Na de exacte toestemming: controleer main en manifest opnieuw; **bij SHA- of remote-drift: fail closed**, geen stilzwijgende re-autorisatie of automatische retry. Gebruik uitsluitend bestaande releasewriter/controller. De gedeelde issuecommentcontroller mag alleen als een geschikt **door eigenaar gecreëerd** issue met correct label en eigenaarcomment bewezen is; een ChatGPT-comment telt niet vanzelf. Anders één handmatige Actions-dispatch met exact release-ID en `DEPLOY-<id>-<main-sha>`. Nooit dubbele dispatch.
7. Na dispatch: zoek de daadwerkelijke `workflow_dispatch`-run zelfstandig, inspecteer jobs, exacte paden, per-file `VERIFIED` SHA-256, status en artifacts. Leg technische uitkomst eenmaal vast in bestaande PR/issue. Technische CI/deploymentbewijzen zijn niet automatisch onafhankelijke OVH-readback of functionele **browseracceptatie**. Vraag uitsluitend die nog ontbrekende menselijke functionele acceptatie. Bij failure bepaal eerst rollback-/incidentstatus, nooit meteen opnieuw uitvoeren.

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

1. UI/CSS: Codex implementeert en test, verifieert PR-CI en vraagt alleen de volgens actuele repositoryregels ontbrekende mergebeslissing; na geautoriseerde merge read-only baseline, manifest en main-CI; nul rituele `go`, één finale productieautorisatie.
2. Securityfix: aparte review/mergegoedkeuring waar vereist; daarna zonder extra `go` voorbereiden, nooit stilzwijgend deployen.
3. Databasewijziging: snapshotcompatibiliteit vooraf, aparte muterende autorisatie en herstelplan.
4. Parallelle releases: concrete pad-/SHA-drift toetsen; onafhankelijke voorbereiding mag doorgaan, geautoriseerde SHA nooit vervangen.
5. Remote drift: vóór write stoppen; bewijs, rollback-/incidentstatus beoordelen en om nieuwe specifieke autorisatie vragen.
