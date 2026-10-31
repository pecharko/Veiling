# Automatiseringsbanen en veilige voortgang

- Ontwikkeling via Codex: gebruik de actieve Codex-omgeving voor onderzoek, volledige implementatie en tests in batches binnen de actieve beurt; CI polls begrensd, inspecteer toepasselijke joblogs en ga door totdat een echte autorisatiegrens is bereikt. Herhaal geen `go` voor PR aanmaken, CI afwachten of testherstel.
- CI-feedback: `.github/workflows/development-ci-feedback.yml` registreert alleen de uitkomst van PR-checks in een eigen Actions-summary met `actions: read`; geen branch writes, PR-comment, merge, nieuwe tests, codefix of deployments. Deze feedback is informatie, niet een actor.
- Fase C (afgesloten sandbox): één gecontroleerde GitHub-refmutatie is onafhankelijk bewezen, maar de generieke vertrouwde writer blijft standaard **uitgeschakeld**. Een ambigue lokale receipt betekent **geen retry**; haal onafhankelijk remote bewijs op en registreer lokale status en remote resultaat apart. Zie `references/phase-c-acceptatie-en-ambigue-write.md`; geen issue/nonce- of commenthergebruik en geen bypass van owner-only autorisatie.
- Productie: hergebruik `.github/workflows/verified-deploy-controller.yml` en `scripts/verified_deploy_controller.py` volledig ongewijzigd, met alle owner-only, scoped SHA, drift, backup en rollbackgates. Geen deploy zonder expliciete finale toestemming.
- Wanneer werk uitloopt buiten de actieve sessie, geef een precieze staat van de uitvoering; beweer niet dat de volgende stap vanzelf wordt uitgevoerd. Een persistent autonome ontwikkelagent vereist afzonderlijke infrastructuur, review en autorisatie.
- PR #396 voorbeeld: controleer alle geografische test- en integratievoorwaarden samen; repareer bekende fouten in één batch, voer volledige tests uit, controleer actuele CI, bescherm DB/restore/crongrenzen.

## Codex als standaard (31 oktober 2026)

Volg `references/codex-development.md`. Een toekomstige merge- of productiegrens blokkeert geen voorafgaand veilig ontwikkelwerk binnen de opdracht. Een helper, draft of groene deeltest is geen eindpunt als nog veilige implementatie van de acceptatiecriteria resteert. Huidige AGENTS.md vereist een afzonderlijke mergebeslissing; deze skill wijzigt dat niet. PR #483 is gesloten als achterhaald; de gewone Codex-naar-PR-werkwijze vervangt de aparte codevoorstellenmotor.
