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


## Fase C4: kandidaatbewijs voor herbruikbaar herstel (offline)

De bestaande module `scripts/development_ci_write_gate.py` levert nu met
`prepare_repair_review(request, original_source)` een deterministisch
**voorstel**, uitsluitend voor de exact herkende PHP-testcorrectie
`return flase;` naar `return false;` (één voorkomen).

De functie controleert het toegestane testpad, de geïsoleerde branch,
de exacte PR-head, het originele Git-blob-OID en de **volledige**
vervangende inhoud. De uitvoer bevat het nieuwe Git-blob-OID,
een SHA-256 van de nieuwe inhoud en een SHA-256 over de gesorteerde,
onveranderlijke reviewvelden (PR, branch, head, pad, oud/nieuw blob).
Onverwachte wijzigingen, extra matches en conflicten worden geweigerd.

Dit is **geen betrouwbare herkomstverificatie**: de aanroeper moet de
input nog via onafhankelijk geauthenticeerde GitHub-reads ophalen. Het
booleaanse veld `author_approved` in het bestaande offline contract
is geen bewijs van echte eigenaarstoestemming. De uitvoer houdt daarom
`can_write=false`, `can_merge=false`, `can_dispatch=false`,
`owner_identity_verified=false` en `nonce_reserved=false`.
Er wordt geen GitHub-token ingelezen of API-call, commit of workflow
gestart. De bestaande live C2-sandboxnonce blijft verbruikt.

**Volgende beveiligingsgrens:** een afzonderlijk gereviewde, herbruikbare
trusted writer moet pas na exacte nieuwe eigenaarautorisatie de herkomst
van de immutable bewijzen verifiëren, een uniek eenmalig nonce beschermen,
de echte GitHub App-installatie en effectieve regels controleren, de branch
via atomische niet-force CAS wijzigen en onafhankelijk readback doen.
Dat mag niet via `development-ci-feedback.yml`, de bestaande
productiecontroller of een onbetrouwbare PR-codecheckout. Een generieke
automatische bugfixer of productiedeployment is niet geïmplementeerd.


## Fase C5: verifiëren van aangeleverde GitHub-readbacks (read-only)

De bestaande write-gate kan met
\`review_github_readbacks(request, pr_payload, branch_payload, file_payload)\`
de exacte PR-, branch- en oorspronkelijke bestandsgegevens vergelijken met
het C4-correctievoorstel. Het contract vereist een open, ongemergde
**draft** sandbox-PR binnen dezelfde repository, een exacte head-SHA,
een overeenkomende branch-ref, het toegestane testpad en een correct
Base64-gecodeerd Git-blob met de verwachte inhoud en grootte.
Bij mismatches, verdwenen/gewijzigde refs, onverwacht bestandstype,
inhoudsdrift, vreemde repository of ongeldige encodering: \`DENY\`.

De invoerpakketten zijn **aangeleverde data, geen geauthenticeerd bewijs**.
Deze functie zelf doet geen GitHub-aanroep, bewaart geen tokens, voert geen
code uit en verifieert geen ononderbroken/atomisch momentopname. Ook bij een
geldig voorstel blijft \`source_provenance_authenticated=false\`,
\`owner_identity_verified=false\`, \`nonce_reserved=false\`,
\`can_write=false\`, \`can_merge=false\` en \`can_dispatch=false\`.

Voor een toekomstig live proces zijn nog nodig: onafhankelijke
GitHub-identiteits-/bevoegdheidsverificatie van alle GETs, bindende en
eenmalige eigenaarautorisatie, afzonderlijke vertrouwde uitvoeringsomgeving,
actieve effectieve branch/tagbescherming, vers controleerbaar CAS,
atomische nonceconsumptie en onafhankelijke readback. Geen onderdeel
van C5 geeft toestemming om de verlopen C2-sandboxnonce te hergebruiken,
de fixture-PR #426 te mergen of de productiecontroller aan te passen.
