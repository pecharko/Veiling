# Overstap naar de Codex-versie

Dit pakket is een bijgewerkt voorstel voor de bestaande persoonlijke skill veiling-deploy-manager, gebaseerd op de cloudversie die op 31 oktober 2026 is gelezen.

## Toepassing

- Repository: plaats deze hele map onder .agents/skills/veiling-deploy-manager. Ze staat daarvoor in een aparte development-PR. Pas na een geautoriseerde merge of bewuste checkout is deze versie beschikbaar in die repository; eventuele sessieverversing/discovery hangt van de Codex-client af.
- Persoonlijke cloudskill: vervang de bestaande SKILL.md en bijbehorende references via de ondersteunde beheerfunctie voor je persoonlijke skills. De huidige sessie heeft alleen skills.list/read en kan die geïnstalleerde cloudversie niet wijzigen. De repository-PR is dus geen cloudinstallatie.
- Behoud alle meegeleverde references. Verwijder geen beveiligings- of historische documenten bij vervanging. Controleer daarna welke skillversie de volgende sessie daadwerkelijk leest.
- De skilltekst installeert geen Codex-app, CLI, GitHub-koppeling, writer of productiecontroller en verleent geen rechten.

## Wijzigingen

- Codex is de standaard voor onderzoek, implementatie, geïsoleerde tests, PR-voorbereiding en herstel van echte CI-fouten.
- Voltooi de gevraagde ontwikkelscope; gebruik toekomstige merge-/productiegrenzen niet als reden om veilig werk te laten liggen.
- Respecteer actuele AGENTS.md, waaronder afzonderlijke mergebeslissingen. Bestaande productie-, DB-, cron-, secrets-, restore- en releasegates blijven behouden.
- De gesloten PR #483 en verbruikte Phase-C-writers worden niet opnieuw als ontwikkelmotor gebruikt.
- Nieuwe references/codex-development.md; gerichte verduidelijkingen in automation-lanes, doorlopende-release-uitvoering en security-and-approvals.
- De overige zeven oorspronkelijke referentiedocumenten zijn inhoudelijk ongewijzigd.

Naast dit documentatiepakket bevat de repository-PR een kleine CI-padherkenningsfix: geldige .agents/skills/-paden voeren beide bestaande integratiesuites uit. Er wordt geen skipregel toegevoegd en onveilige paden blijven geweigerd. De geografische feature-PR #396, productie en workflows worden niet gewijzigd.
