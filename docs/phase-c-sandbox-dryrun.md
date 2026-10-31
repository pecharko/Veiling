# Fase C sandbox — dry-run zonder schrijfrechten

Deze workflow is een voorbereiding, geen activatie van automatische correcties. Hij draait uitsluitend bestaande offline contracttests met `contents: read` en zonder persistente checkout-credentials. Hij schrijft geen branch, PR, issue, workflowdispatch, productiegegevens of deploymentbestanden. De bestaande releasecontroller blijft ongewijzigd.

## Gates voor toekomstige write-enabled sandbox
1. Onafhankelijke eigenaarautorisatie gekoppeld aan een specifiek test-PR, exact immutable head-SHA en blob-SHA; nooit een door de gebruiker vrij invulbare `author_approved` waarde vertrouwen.
2. Beperk uitvoer tot één `tests/*_test.php` op een herkenbare `test/phase-c-*` branch. Nieuwe CI op de gewijzigde exacte head verplicht.
3. Eenmalige attempt; vergelijk head-SHA onmiddellijk vóór write. Stop bij drift of onbekende inhoud.
4. Scheid vertrouwde controllercode van de te herstellen onbetrouwbare PR-inhoud; voer die inhoud niet uit met een schrijftoken.
5. Autoriseer minimale GitHub-content write pas na aparte securityreview. Geen OVH-, release-, database-, admin-, workflow- of secretsrechten.
6. Geen automatische merge, force-push, retry of deployment.

De dry-run workflow zelf mag veilig via een aparte pull request worden beoordeeld, maar het activeren van een schrijvende workflow blijft een afzonderlijke, expliciete veiligheidsbeslissing.
