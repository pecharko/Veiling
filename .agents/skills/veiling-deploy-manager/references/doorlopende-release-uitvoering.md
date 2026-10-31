# Doorlopende uitvoering zonder overbodige go-beurten — les PR #397–#401 (29 oktober 2026)

## Foutpatroon en herstel
Bij de cronlog-release stopte de assistent na (a) een net gestarte CI-check, (b) een gewone PR-merge, (c) een release-requestmerge, (d) een aangemaakte manifest-PR en (e) een manifestmerge. Telkens moest de eigenaar `go` antwoorden. Dit zijn **voortgangstoestanden, geen goedkeuringsgrenzen**. Herhaal deze fout niet, ook niet als de laatste check pas net in `queued` of `in_progress` staat.

## Uitvoeringslus voor één actieve beurt
1. Bepaal de eerstvolgende toegestane stap en voer die meteen uit. Leg een compacte interne toestand vast: `feature_PR -> feature_CI -> feature_merge -> main_push_CI -> path_routing -> release_request_PR -> request_CI -> request_merge -> readonly_auto_manifest -> manifest_PR -> manifest_CI -> manifest_merge -> main_push_CI -> final_authorization -> deployment -> verification -> browser_acceptance`.
2. Na **iedere** succesvolle GitHub-actie herbereken de volgende stap; een groen resultaat of nieuwe PR is niet vanzelf een terminal antwoord. Verifieer steeds de exacte PR-head, actuele main, parallelle overlap en relevante CI-jobs.
3. Bij `queued`/`in_progress` of tijdelijk ontbrekende runs: controleer de concrete jobstappen; voer andere veilige read-only voorbereidingen uit; hercontroleer daarna met een **begrensd** aantal polls. Gebruik korte voortgangsupdates, geen verzoeken om `go`. Geen busy loop, geen claim van achtergrondmonitoring na afloop van het antwoord.
4. Bij falende CI: lees de mislukte joblog en herstel de concrete regressie in de bestaande branch; laat CI opnieuw lopen. Vraag geen gebruikersopdracht voor een standaard testfix binnen eerder goedgekeurde scope. Als wijzigingen de scope verruimen, beslis opnieuw over autorisatie.
5. Bij `Open manifest review PR` mislukt maar read-only hashverzameling groen: zoek eerst de bestaande `release-manifest/<release-id>-<run>` branch, controleer de echte manifestinhoud en jobs, open zelf een PR. Geen dubbele preflight of nieuwe release-aanvraag. PR #401 is hiervoor het referentievoorbeeld.
6. Neem actuele `main`-SHA **na** de manifestmerge, niet de featurecommit. Detecteer SHA- en padconflicten met parallel werk; een gewijzigde SHA kan de release blokkeren, zelfs zonder overlappende bestanden.
7. Stop uitsluitend bij (a) een aantoonbare veiligheids-/goedkeuringsgrens, (b) een echte fout of toolblokkade die niet veilig kan worden verholpen, of (c) redelijke sessie-/toolgrens nadat andere veilige stappen zijn uitgeput. Rapporteer in één zin de eerstvolgende concrete stap; vraag geen rituele `go`.
8. Na exacte productieautorisatie: geen extra `go` of `groen` vragen; gebruik ondersteunde controllerroute, anders één handmatige Actions-dispatch met precieze waarden. De uitkomst uitsluitend via verifieerbare workflow-/artifactbronnen vaststellen; `groen` van gebruiker als melding onderscheiden van technisch bewijs. Vraag na verificatie alleen functionele browseracceptatie en registreer die eenmaal.

## Concrete autorisatiegrenzen, nooit wegautomatiseren
- Riskante merge: security/auth, releasegate, database/schema, restore, data-archivering en beschermde cronwijzigingen vragen aparte expliciete mergegoedkeuring indien vereist.
- Productie-mutatie: specifieke immutable commit, exact manifest en bestandspaden, bewezen remote SHA/ABSENT, hostkey, pre-write driftcontrole, codeback-up, rollback en readback. Geen code-upload op basis van `go`.
- Databasewrite, archivering/DELETE, cron activeren/wijzigen, restore, secrets/rechten: ieder afzonderlijk specifiek geautoriseerd.
- Laat een gebruiker nooit hetzelfde al gegeven akkoord herhalen. Een gewone `go` ná een expliciet autorisatieverzoek is nog steeds niet de ontbrekende exacte autorisatie.

## Meetlat
Normale release: **nul tussenliggende `go`-vragen**, **één finale productieautorisatie**, eventueel **één handmatige workflowstart** bij ontbrekend connectorrecht en **één functionele browseracceptatie**. Alle technische tussenstanden zijn autonoom of statusberichten, geen nieuwe opdrachten.

## Codex voltooit eerst de gevraagde ontwikkelscope

Gebruik `references/codex-development.md`. De toestand feature_CI is pas ontwikkelgereed wanneer alle gevraagde acceptatiecriteria zijn afgewerkt of een concrete blokkade is bewezen. Een mogelijke latere mergegrens is geen reden om veilige implementatie, tests, privacy-/archief-/herstelvoorbereiding of documentatie over te slaan. Iedere mergeovergang blijft afhankelijk van actuele AGENTS.md en exact geldige eigenaarautorisatie. De huidige AGENTS.md vereist een afzonderlijke mergebeslissing, ook voor gewone wijzigingen. Na toestemming de veilige keten direct hervatten; geen tweede go voor dezelfde actie.
