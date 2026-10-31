# Fase C8 — geïntegreerde, niet-uitvoerbare sluitingsproef

## Wat deze fase toevoegt

De nieuwe `scripts/development_ci_c8_execution_closure.py` roept de
C7-veiligheidsreview opnieuw aan vanuit de volledige, aangeleverde
bron-/eigenaar-/App-/rulesetgegevens. Bij een geldige **offline** review
doorloopt een zuivere lokale toestandssimulatie het reeds bestaande C2
one-shot protocol:

1. `reserve_confirmed` → `NONCE_RESERVED` (*alleen gesimuleerd*);
2. `submit_ref_update` → `REF_UPDATE_ATTEMPTED` (*alleen gesimuleerd*);
3. `verified_exact` → `VERIFIED` (*alleen gesimuleerd*).

Ongeldige volgorde, dubbele poging, vervalste eigenaar- of App-data,
gewijzigde head/hash, extra rechten, uitgeschakelde rulesets of een
verbruikte nonce leveren `DENY`.

**Geen enkele stap voert een API-mutatie uit of maakt een echte nonce
aan.** De uitvoer onderscheidt de gesimuleerde teller expliciet van
`git_ref_updates_executed=0`. Zelfs een geslaagde eindtoestand houdt
`write_allowed=false`, `can_merge=false`,
`activation_allowed=false`, `retry_allowed=false` en
`production_authorized=false`.

Het entrypoint `execute_one_shot` is onvoorwaardelijk uitgeschakeld.
Er is geen nieuwe workflow, GitHub App-token, environment secret,
activatievlag, branchmutatie of database-/OVH-pad.

## Hoe fase C echt functioneel afgemaakt wordt

De volledige offline voorbereiding C1–C8 kan technisch worden
geaccepteerd zodra de PR-CI, securityreview, gescopeerde merge en
afzonderlijke main-push-CI zijn geslaagd. Dat is **niet hetzelfde** als
de activering van een herbruikbare codecorrectie-writer.

Voor de eerste werkelijke, nieuwe sandboxuitvoering blijven samen
nodig:

1. Een nieuw, onafhankelijk gereviewd vertrouwd uitvoeringsproces
   met minimale App-rechten en zonder het uitvoeren van PR-code of
   de toegang tot productiecredentials.
2. Onafhankelijk geauthenticeerde, actuele GitHub- en App-antwoorden,
   met controle van de *effectieve* bescherming van de specifieke
   doelbranch en het nonce-tagpatroon; statische ruleset-ID's zijn
   hiervoor onvoldoende.
3. Een **nieuwe** geïsoleerde sandbox-PR en **nieuw**, nog niet
   geconsumeerd, duurzaam nonce-ID. PR #426 en issue #427 zijn
   definitief afgesloten als schrijfsandbox en mogen nooit worden
   hergebruikt of gemerged.
4. Eén exacte, voorafgaande eigenaarautorisatie op repository, PR,
   head/blob/candidate-SHA, path, nonce, operatie en installatie;
   een C7 `REVIEW_ONLY`-comment is geen live-autorisatie.
5. Eenmalige, racebestendige reservering van de nonce en een
   atomische niet-force compare-and-swap van de exacte branch-head.
   Bij onduidelijke uitkomst **geen retry**; eerst alleen read-only
   herstel-/incidentonderzoek.
6. Onafhankelijke postwrite-readbacks van ref, nonce, blob, commit
   en CI, gevolgd door een nieuwe gescopeerde beslissing vóór elke
   volgende wijziging. Geen automatische merge of productieactie.

De bestaande productiecontroller, releasegates, OVH, databases en
GitHub Actions-`development-ci-feedback.yml` blijven volledig
gescheiden van deze toekomstige writer.

## Autorisatiegrens

Deze PR bereidt uitsluitend de lokale toestandstest en de
documentatie voor. **Mergegoedkeuring van C8 is geen toestemming
voor het activeren, testen of uitvoeren van een live writer.**

## Onafhankelijke ruleset-readback op 30 oktober 2026

GitHub ruleset #26113993 is actief en beperkt tot
`refs/heads/test/phase-c-writer-fixture-20261029`. Deze regel beschermt
tegen `deletion` en `non_fast_forward`, maar dekt **geen nieuwe
testbranch** automatisch.

GitHub ruleset #26113833 is actief voor
`refs/tags/phase-c-once-issue-*` en beschermt tegen `update` en
`deletion`; niet met `non_fast_forward` (dat is een branchregel).
De C7-shapecontrole en regressietests zijn in deze PR met deze
werkelijke gescheiden types in overeenstemming gebracht.

De huidige rulesetconfiguratie mag niet door C8 worden gewijzigd.
Vóór een nieuwe live proef moet de **effectieve bescherming van de
exacte nieuwe branch** afzonderlijk worden aangetoond. Dat kan een
nieuwe expliciet goedgekeurde beveiligingsinstelling vereisen.
Een oud ruleset-ID of een positieve offline review vervangt die
bescherming niet.
