# Fase B — beveiligde releasecontroller: implementatiecontract

Status: **ontwerp ter review; geen runtime-activatie**. Referentie: fase A op main
`6049f83df2cb0be81b28d0fb2fd56b231b5009c8`.

## Bestaande componenten (niet opnieuw implementeren)

- `.github/workflows/shared-ovh-auto-manifest.yml`: maakt na een read-only
  SFTP-controle een reviewbaar manifest vanuit een release-request.
- `.github/workflows/shared-ovh-release.yml`: bestaande **handmatige**
  `workflow_dispatch` met `release_id` en
  `confirmation=DEPLOY-<release_id>-<actuele-main-SHA>`.
- `scripts/shared_ovh_release.py`: valideert bronhashes en toegestane paden,
  controleert remote hashes vlak vóór upload, maakt een codeback-up, verifieert
  readback en probeert rollback bij een fout.
- `scripts/controller_evidence_collector.py` en bestaande fase-A-modules:
  afzonderlijke, read-only Actions/rollback-metadatacontroles. Ze bewijzen
  **geen** onafhankelijke OVH-toestand of browseracceptatie.

## Prioriteit en onafhankelijke grenzen

1. **B1 — Passieve post-run bewijsopvolging.** Alleen bij voltooiing van de
   *exacte* gedeelde releaseworkflow. De bewakingsjob krijgt uitsluitend
   `contents: read`, `actions: read`, geen production environment, geen
   SFTP-credentials en geen GitHub-write. Pin repository, workflowpad,
   `event=workflow_dispatch`, `head_branch=main`, immutable 40-hex SHA,
   run-ID en job-identiteit. Haal exact één artifactinventaris op, valideer
   ZIP-inhoud en gebruik de bestaande fase-A-reconciler. Geen generieke
   uitvoering van code uit artifacts of externe PR-checkouts. Fouten,
   ontbrekende of verlopen artifacts: status `INCOMPLETE`, niet groen.
2. **B2 — Betrouwbare read-only rapportage.** Rapporteer machineleesbaar
   welke bron elk bewijs heeft. Onderscheid GitHub-run success, SFTP-readback
   geclaimd door dezelfde runner, externe server-readback (apart),
   terugrolbewijs en handmatige functionele browseracceptatie. Maak geen
   feitelijke productieclaim uit alleen repository- of artifactmetadata.
   Bewaar minimaal zeven dagen evidence/rollback met een controleerbare
   artifact-ID en vervaldatum.
3. **B3 — Bevoegde start, afzonderlijke autorisatie.** Een daadwerkelijke
   controller-dispatch is pas aan de orde nadat een *aparte* securityreview
   en expliciete immutable mergegoedkeuring zijn gegeven. De autorisatie
   bindt samen: release-ID, huidige main-SHA, exacte manifestpaden,
   source- en bewezen remote SHA-256/ABSENT, workflow-ID, éénmalige
   autorisatie en vervaldatum. De writer verifieert opnieuw serverdrift
   vóór de eerste write. Branch-, run-, release- of manifestdrift doet
   fail-closed. Niet via repository-push, comment, PR-label of onofficiële
   endpoint een writer triggeren. GitHub connector biedt voorlopig geen
   `workflow_dispatch` actie; geen fictieve automatische dispatch.
4. **B4 — Acceptatie en afronding.** Pas na werkelijk geverifieerde run en
   afzonderlijke gebruikersacceptatie het bestaande issue/manifest-PR
   annoteren. Nooit automatische browseracceptatie.

## Vereiste negatief- en regressietests vóór implementatie

- Onjuist workflowpad, SHA, repo, event, runstatus, ontbrekende jobs/steps.
- Dubbele/ontbrekende artifacts, ZIP-slip/oversized ZIP, verlopen bewijzen,
  onbekend schema, vervallen token, paginatie > één pagina.
- Geen autorisatie bij alleen `go`, CI success, manifestmerge, `groen` of
  artifactstatus VERIFIED; geen dubbele dispatch bij retries.
- Veranderde `main`, bronhash, remote baseline, manifestinhoud of
  bestandsallowlist vóór upload blokkeert iedere productie-write.
- Provenance van rollbackartifact en readback, plus bewijs dat een interne
  runnerreadback niet onafhankelijk extern bewijs wordt genoemd.
- Verifieer echte PHP-, kavel- en snapshot-CI op iedere relevante PR-head;
  markeer passende NVT afzonderlijk.

## Scope voor de eerstvolgende B1-code-PR

Eén read-only GitHub workflow en één herbruikbare orchestratielaag met
offline mocktests. Geen verandering aan `shared-ovh-release.yml`,
`shared_ovh_release.py`, production environment, OVH secrets,
branch protection, database, cron of releasegate. De orchestrator mag
uitsluitend afronden met een bewijsrapport. Voor toevoegen van workflow-
permissions, events of triggers is een afzonderlijke veiligheidsreview en
exacte SHA-mergeautorisatie vereist. Maak geen productieactie van een
post-run rapportagejob.

## Beslissing

Dit document autoriseert **geen** merge, automatische workflowstart of
productiedeployment. Het is een toetsbare ontwerpbasis voor B1 en volgende
afzonderlijk goedgekeurde PR's.
