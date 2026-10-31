# Codex voor ontwikkeling; Veiling Deploy Manager voor de gecontroleerde keten

Versie: 31 oktober 2026. Deze instructies benutten bestaande mogelijkheden. Ze installeren of starten geen Codex-service, GitHub App, writer, timer of deploymentcontroller. De gebruikersopdracht en actuele repositoryregels bepalen bevoegdheden; historische voorbeelden geven geen autorisatie.

## 1. Kies de werkplek en verifieer de taak

1. Werk rechtstreeks in de actieve Codex-omgeving als die beschikbaar is. Start geen duplicaattaak en beweer niet dat een tool Codex gestart heeft zonder echte startbevestiging.
2. Lees de actuele AGENTS.md, gitstatus, main, de gevraagde issue/PR, relevante bron/tests en open PRs met overlap. Gebruik de GitHub-connector voor actuele remote feiten. Gebruik lokale tools voor zoeken, editen, uitvoeren en vergelijking. Als een connector de nodige bewerking ondersteunt, verkies die voor GitHub-bewerkingen.
3. Gebruik een geïsoleerde developmentbranch of worktree; niet main, een releasebranch of een Phase-C-sandbox. Behoud bestaande lokale wijzigingen. Synchroniseer met main zonder ongevraagde force-push of verlies van geschiedenis; nieuwe main is pas een conflict na controle van paden en ancestry.
4. Leg de gebruikerswens vast als concrete eindfunctionaliteit met acceptatiecriteria. Maak onderscheid tussen ontwikkelvoltooiing, mergebesluit, read-only releasevoorbereiding en afzonderlijke productieactivering. Een fase-indeling is planning, geen extra toestemmingsgrens.
5. Vraag ontbrekende productkeuzes vroeg en alleen wanneer bron/context ze niet oplossen. Ga door met onafhankelijke onderdelen. Hergebruik eerder gegeven besluiten, bijvoorbeeld de keuze over oude snapshots, en eerdere exact geldige autorisaties.

## 2. Voer het volledige veilige ontwikkelwerk uit

- Onderzoek een onduidelijke fout eerst en maak een reproduceerbare test als dat zinvol is. Implementeer vervolgens de gevraagde functionaliteit met bestaande architectuur, geen nieuwe controller of writer om een gewone codewijziging te maken.
- Een opdracht voor een feature dekt de benodigde lokale code, documentatie, testfixtures en ontwikkeling binnen scope. Nieuwe GitHub-PRs alleen wanneer de opdracht dat omvat; hergebruik een bestaande passende PR. GitHub-writes blijven beperkt tot de geautoriseerde ontwikkelbranch en PR.
- Werk samenhangende afhankelijkheden af. Bij geografische statistieken zijn lookup, dagdeduplicatie, transacties, rapportage, privacy, handmatig/cron-archiveren, snapshot/herstel/reset/retentie en activeringsvolgorde samen te toetsen. Alleen een geteste helper maakt de volledige feature niet af.
- Schrijf en test muterende schema-, cron- of herstelcode uitsluitend met wegwerpdata in een aantoonbaar geïsoleerde lokale/CI-omgeving. Dit staat los van productie-SQL, echte archivering, live restore, remote CLI of cronactivering. Lees geen productieconfiguratie of secrets om een lokale test te laten werken.
- Mogelijke toekomstige productiegoedkeuring of een nog niet gegeven mergebeslissing houdt de voorbereiding niet tegen. Stop pas het afhankelijke werk wanneer een werkelijk ontbrekend besluit, echte externe voorziening, bevoegdheidsgrens of toolgrens dat noodzakelijk maakt.
- Benut onafhankelijke toolaanroepen in batches. Gebruik subagents alleen als de gebruiker of toepasselijke instructies expliciet om delegatie/parallel agentwerk vragen; aanwezigheid van Codex of deze skill is op zichzelf geen verzoek.
- Voeg geen ongevraagde infrastructuur, ruimere rechten, externe services of permanente agents toe. Externe MaxMind-accounts, gelicentieerde data en secrets worden niet door een algemene ontwikkelopdracht geautoriseerd.

## 3. Test passend en herstel echte CI-fouten

1. Start met gerichte checks: PHP 8.2 syntax, relevante functionele regressies en git diff --check. Voeg tests toe voor wezenlijke fouten/randgevallen; geen test die slechts de implementatie kopieert.
2. Test SQL-semantiek, concurrency, locking en rollback op de vereiste MySQL-versie. SQLite en statische bronchecks bewijzen geen MySQL-gedrag. Mock-/fixturetests bewijzen geen echte reader, MMDB, OVH-runtime of productieacceptatie.
3. Houd testgebruikers beperkt tot de wegwerpdatabase. Gebruik veilige foutinjectie; los een CI-fout niet op door SUPER toe te voegen, server-/productiegates uit te zetten of credentials te verruimen.
4. Open of update de PR in een coherente batch. Controleer actuele head en volledige diff en laat de bestaande pull_request-CI lopen. queued/in_progress is een tussenstand. Poll begrensd met voortgangsupdates en voer intussen overige veilige controles uit.
5. Lees de concrete falende joblog. Herstel een regressie binnen scope op dezelfde developmentbranch, herhaal passende checks en controleer CI op de nieuwe head. Vraag geen nieuwe opdracht voor gewoon testherstel.
6. Bewijs dat de relevante jobs echt tests hebben uitgevoerd. Groen met NOT_APPLICABLE/skipped is geen positief testbewijs voor gewijzigde runtime. Houd lokale tests, PR-CI, main-push-CI, deploymentbewijs, remote readback en browseracceptatie apart.
7. Bij een aantoonbaar bestaande, niet door de wijziging veroorzaakte CI-fout: verzamel de baseline en impact; los die binnen scope op of rapporteer de precieze blokkade. Verbreed de taak niet stilzwijgend en verzwak geen check.

## 4. Geef een concrete overdracht aan de bestaande gates

Vóór een mergebeslissing levert Codex één reviewbaar resultaat:
- Gewenst gedrag en resultaat per acceptatiecriterium; concrete ontbrekende functionaliteit of blokkade.
- Repository, developmentbranch, exacte immutable head-SHA en volledige gewijzigde bestandslijst of exact-head diff.
- Testcommando's, resultaten, CI-run/joblinks en bewijs dat toepasselijke tests liepen.
- Veiligheidsimpact, afhankelijkheden, main- en padoverlap en de benodigde eerstvolgende autorisatie.

De huidige repository-AGENTS.md verbiedt zelfstandig mergen. Een gewone feature-opdracht, deze skill, PR-status of groen CI verandert dat niet. Bestaande specifieke eigenaarautorisatie niet opnieuw vragen zolang actie, scope, SHA en voorwaarden exact blijven gelden. Bij drift eerst opnieuw verifiëren en voor de veranderde risicovolle actie nieuwe exacte toestemming verkrijgen.

Na een geautoriseerde merge voert de actieve sessie meteen de overige toegestane stappen van de bestaande releaseketen uit: main/push-CI, padroutering, read-only baselines en manifestvoorbereiding voor zover afzonderlijk bevoegd. Een ontbrekend mergebesluit is geen reden om onafhankelijk veilig analysewerk te laten liggen. Voor productie blijven exacte SHA, allowlist, per-file remote SHA/ABSENT, driftcheck, pinned hostkey, codeback-up, rollback en readback verplicht.

## 5. Houd de drie uitvoeringsbanen gescheiden

- **Codex in de actieve sessie:** daadwerkelijk onderzoek, code, tests, PR-voorbereiding, CI-inspectie en herstel binnen scope.
- **GitHub Actions:** voert de bestaande workflows uit en rapporteert resultaten. CI-feedback start niet zelfstandig een nieuwe Codex-beurt of codecorrectie.
- **Productiecontroller/eigenaar:** mag uitsluitend de apart geautoriseerde, bestaande releaseprocedure uitvoeren. Geen CI-trigger die autonoom naar deployment doorstroomt.

PR #483 is op 31 oktober 2026 op verzoek gesloten als achterhaald. Gebruik de gewone Codex-werkwijze; bouw geen vervangende deterministische typo-motor of Phase-C-writer. Historische Phase-C-issues/nonces zijn nooit herbruikbare ontwikkelrechten. Bij ambigue externe writes: stop de muterende keten, geen herhaling; reconcilieer onafhankelijk en behoud lokale receipt plus remote bewijs afzonderlijk.

## 6. Stop en rapporteer correct

Stop alleen het werkelijk geblokkeerde werk en rond onafhankelijke veilige stappen af. Een einde aan een beurt betekent geen achtergrondmonitoring. Meld wat klaar is, welke tests bewezen zijn en precies wat nog ontbreekt.

Bij een echte goedkeuringsgrens: één concreet, volledig voorbereid autorisatievoorstel volgens de kernskill, met de exacte regel uit AGENTS.md/skill die de grens vereist. Geen algemeen verzoek om go, geen toestemming voor een nog niet uitgewerkt voorstel, en geen vraag om hetzelfde ongewijzigde akkoord opnieuw te geven. Gebruik alleen werkelijk beschikbare interactieve componenten; anders een compacte tekstvariant.
