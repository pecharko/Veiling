# Doorlopende uitvoering zonder overbodige go-beurten — les PR #397–#401 (29 oktober 2026)

## Foutpatroon en herstel
Bij de cronlog-release stopte de assistent na (a) een net gestarte CI-check, (b) een gewone PR-merge, (c) een release-requestmerge, (d) een aangemaakte manifest-PR en (e) een manifestmerge. Telkens moest de eigenaar `go` antwoorden. Dit zijn **voortgangstoestanden, geen goedkeuringsgrenzen**. Herhaal deze fout niet, ook niet als de laatste check pas net in `queued` of `in_progress` staat.

## Uitvoeringslus voor één actieve beurt
1. Bepaal de eerstvolgende toegestane stap en voer die meteen uit. Voor een gewone release: routeer de runtimepaden, verzamel baselines en bundel code, tests en manifest vóór de eerste PR-review. Volg PR-CI, de exacte mergeautorisatie, main-push-CI, de exacte productieautorisatie en de eenmalige uitrol met verificatie. De historische keten met release-request en manifest-PR geldt alleen voor passende uitzonderingen.
2. Na **iedere** succesvolle GitHub-actie herbereken de volgende stap; een groen resultaat of nieuwe PR is niet vanzelf een terminal antwoord. Verifieer steeds de exacte PR-head, actuele main, parallelle overlap en relevante CI-jobs.
3. Bij `queued`/`in_progress` of tijdelijk ontbrekende runs: controleer de concrete jobstappen; voer andere veilige read-only voorbereidingen uit; hercontroleer daarna met een **begrensd** aantal polls. Gebruik korte voortgangsupdates, geen verzoeken om `go`. Geen busy loop, geen claim van achtergrondmonitoring na afloop van het antwoord.
4. Bij falende CI: lees de mislukte joblog en herstel de concrete regressie in de bestaande branch; laat CI opnieuw lopen. Vraag geen gebruikersopdracht voor een standaard testfix binnen eerder goedgekeurde scope. Als wijzigingen de scope verruimen, beslis opnieuw over autorisatie.
5. Als de uitzonderingsroute met automatische manifestverzameling is gebruikt en alleen het openen van de manifest-PR mislukt, controleer de bestaande runnerbranch en de authentieke resultaten voordat je de PR opent. Hergebruik de meting. PR #401 is hiervan het historische voorbeeld.
6. Gebruik voor productie de actuele `main`-SHA die code en manifest bevat. Controleer pad- en SHA-drift, ook na een merge met een andere bestandscope.
7. Stop uitsluitend bij (a) een aantoonbare veiligheids-/goedkeuringsgrens, (b) een echte fout of toolblokkade die niet veilig kan worden verholpen, of (c) redelijke sessie-/toolgrens nadat andere veilige stappen zijn uitgeput. Rapporteer in één zin de eerstvolgende concrete stap; vraag geen rituele `go`.
8. Na exacte productieautorisatie: gebruik eenmaal een beschikbaar en bevoegd dispatchmiddel. Geef alleen bij een toolbeperking één handmatige Actions-instructie met precieze waarden. Verifieer de uitkomst via workflowlogs en artefacten. Vraag alleen om de nog ontbrekende functionele browseracceptatie en registreer die eenmaal in de bestaande PR wanneer dat binnen de opdracht valt.

## Concrete autorisatiegrenzen, nooit wegautomatiseren
- Merge: iedere PR vereist een afzonderlijke, exacte eigenaarautorisatie volgens AGENTS.md. Voor security/auth, releasegates, database/schema, restore, data-archivering en beschermde cronwijzigingen geldt ook de gespecialiseerde review.
- Productie-mutatie: specifieke immutable commit, exact manifest en bestandspaden, bewezen remote SHA/ABSENT, hostkey, pre-write driftcontrole, codeback-up, rollback en readback. Geen code-upload op basis van `go`.
- Databasewrite, archivering/DELETE, cron activeren/wijzigen, restore, secrets/rechten: ieder afzonderlijk specifiek geautoriseerd.
- Laat een gebruiker nooit hetzelfde al gegeven akkoord herhalen. Een gewone `go` ná een expliciet autorisatieverzoek is nog steeds niet de ontbrekende exacte autorisatie.

## Meetlat
Normale release: **één PR, één exacte mergeautorisatie en één aparte exacte productieautorisatie**. Vraag alleen om een handmatige workflowstart als het beschikbare, bevoegde gereedschap die niet kan uitvoeren. Vraag na technische verificatie uitsluitend de eventueel nog ontbrekende functionele browseracceptatie.

## Codex voltooit eerst de gevraagde ontwikkelscope

Gebruik `references/codex-development.md`. De toestand feature_CI is pas ontwikkelgereed wanneer alle gevraagde acceptatiecriteria zijn afgewerkt of een concrete blokkade is bewezen. Een mogelijke latere mergegrens is geen reden om veilige implementatie, tests, privacy-/archief-/herstelvoorbereiding of documentatie over te slaan. Iedere mergeovergang blijft afhankelijk van actuele AGENTS.md en exact geldige eigenaarautorisatie. De huidige AGENTS.md vereist een afzonderlijke mergebeslissing, ook voor gewone wijzigingen. Na toestemming de veilige keten direct hervatten; geen tweede go voor dezelfde actie.
