# Registratie van echte releaseworkflows

De productiecontroller in `scripts/verified_deploy_controller.py` accepteert uitsluitend workflows die expliciet in `scripts/verified_deploy_registry.json` staan. De registratie is een *allowlist*, geen algemene toestemming voor een release.

## Integratiecontract

Voor elke nieuwe release is een afzonderlijke veiligheidsreview nodig:

1. Controleer de exacte uploadbestanden, volgorde, uitgesloten gegevens en afhankelijkheden. Een toekomstige workflow krijgt **niet** automatisch toegang via een bestandsnaam.
2. De workflow moet handmatig op `main` dispatchbaar zijn en zelf de exacte release-SHA, het bevestigingswoord, checksums en alle eigen veiligheidsvoorwaarden controleren.
3. Gebruik een afzonderlijke read-only preflight die het exacte `main`-commit, de remote hashes en het manifest `preflight-sha256.json` levert. De controller accepteert alleen een geslaagde run van maximaal 24 uur met een niet-vervallen artifact.
4. Voeg voor die workflow `preflight`, `artifact_prefix`, `confirmation`, `paths` en `hash_inputs` toe aan de centrale registry. De lijst en volgorde van hashes moeten exact overeenkomen met de werkelijke deploy-inputs.
5. Voeg positieve en negatieve autorisatietests toe, met aandacht voor verkeerde SHA, verkeerde manifestidentiteit, verlopen preflight, dubbele goedkeuring en ongeautoriseerde workflows.
6. Draai `python3 tests/registered_release_contract_test.py` plus de drie verplichte CI-suites op de actuele PR-head. Nieuwe registratie is beveiligingsgevoelig: laat ze afzonderlijk beoordelen.
7. Een groene CI en een merge geven geen productieautorisatie. Eerst specifieke releasegoedkeuring, vervolgens afzonderlijke definitieve toestemming voor productie. Een aanvraag wordt maximaal één keer geconsumeerd.

## Actuele compatibiliteit

- **Geregistreerd:** `kavelnummer-thumbnails-release.yml` met `kavel-release-readonly-preflight.yml`. Het bestaande gecontroleerde autorisatiecontract blijft behouden.
- **Niet geregistreerd:** `lot-sale-three-simple-release.yml`. Die vereist onder meer `plan_run_id`, `schema_ready` en de expliciete risicokeuze zonder rollback. Het huidige generieke controllercontract ondersteunt die eisen **niet**. Nooit het schema-attest of de no-rollbacktoestemming weglaten om registratie mogelijk te maken.
- Andere gerichte oudere workflows blijven niet geregistreerd totdat per workflow passende bewezen preflightbewijzen, inputoverdracht en veiligheidschecks aanwezig zijn.

De CI-contracttest toetst statische structurele compatibiliteit, niet een echte remote productiepreflight of de aanwezigheid van een menselijke goedkeuring. Gebruik nooit test- of demonstratiebewijzen voor een echte deploy.
