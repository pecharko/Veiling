# Fase C — gecontroleerde schrijver: bevoegdhedenontwerp (nog NIET activeren)

De bestaande `development-ci-feedback.yml` leest alleen GitHub-runstatus. `development_ci_advisor.py` classificeert offline fouten; `development_ci_repair_prototype.py` maakt enkel een geïsoleerd voorstel. `development_ci_write_gate.py` definieert de voorwaarden voor eventuele toekomstige schrijfrechten maar kan zelf niets schrijven.

## Minimale toekomstige bevoegdheden
- Alleen afzonderlijk aangemaakte, herkenbare `test/phase-c-*`-branches van de eigenaar, **nooit** `main`, een bestaande featurebranch of een deploymentbranch.
- Alleen één PHP-testbestand `tests/*_test.php` (geen submappen, schema, snapshots, workflows, `private/`, `scripts/`, `catalogus-public/` of releasebestanden).
- Exacte PR-head-SHA plus huidige content-blob-SHA bij elke commit; bij drift stoppen. Geen force-push, geen nieuwe permissies voor bestaande productiecontroller.
- Maximaal één herstelpoging per goedgekeurd proef-PR; geen automatische retry of merge. Eerst alleen offline voorstel en CI-controle.
- Afzonderlijke expliciete eigenaarstoestemming vóór het activeren van een toekomstige **write-enabled** GitHub-workflow of app. De huidige user-opdracht om voorbereidingen te starten is geen goedkeuring van een nieuwe GitHub-write-gate.
- Token met minimaal mogelijke bevoegdheden, uitsluitend in geïsoleerde job, nooit in een job die onbeoordeelde PR-code kan uitvoeren. Geen `pull_request_target` met een onbetrouwbare checkout. Geen toegang tot OVH-secrets of production environment.
- Geen workflowdispatch, OVH-mutatie, databasewijziging, releasegate- of role-aanpassing. Securityreview van alle workflowwijzigingen verplicht.

## Volgende echte verificatie
1. Bevestig de offline contracttests en de nieuwste PR-head-CI.
2. Securityreview van GitHub-context- en token-isolatie, workflowtrigger en commit-CAS.
3. Pas na aparte goedkeuring: afzonderlijke sandbox-writerworkflow en één eigenaar-geautoriseerde test op een geïsoleerde branch.
4. Bevestig één beperkte wijziging, nieuwe CI, nul ongeautoriseerde wijzigingen, geen merge of deployment.

De contractstatus `ELIGIBLE_FOR_SEPARATE_WRITE_APPROVAL` is **geen toestemming** en heeft expliciet `can_write=false`.
