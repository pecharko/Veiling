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

## Afgeronde bouwblokken en actuele B3-gap (27 oktober 2026)

- B1: passieve post-run-check gemerged via PR #274, main-commit
  `ec9c08c1d8d63ce99dcb0c9c0fea4b50975c5203`.
- B2: herkomstlabels in read-only rapport gemerged via PR #275, main-commit
  `6a1eb04286ce565d776bc578e7e1d8ce49c7c768`.
- `scripts/shared_ovh_controller_adapter.py` bestaat **al**: exact
  goedkeuringsformaat, owner issue/comment, 24-uurs frisheid, bron- en
  manifesthashes, preflightidentiteit, padallowlist, anti-replay via
  `previously_closed`, en een **niet-uitgevoerde** dispatch-argumenttuple.
  Hergebruik deze module en de uitgebreide offline regressietests;
  ontwerp geen duplicaat.
- `scripts/verified_deploy_controller.py` bestaat als legacy gecontroleerde
  issue-gebaseerde controller. Koppeling van de gedeelde OVH-adapter aan
  deze uitvoerende controller is momenteel **niet aanwezig**.
- De bestaande `shared-ovh-release.yml` blijft de enige code-writer,
  met verplichte afzonderlijke productietoestemming en driftcontrole.

## B3 uitvoeringsplan — drie afzonderlijke reviewbare stappen

1. **B3.1: dry-run authorisatie- en replaycontract.** Vergelijk een echt
   owner-approvalcomment (niet het woord 'go') met het bestaande
   `shared_ovh_controller_adapter.review_offline`-contract. Vertrouw niet
   op issuecommentbody zonder GitHub-auteur, comment-ID, originele
   aanmaak-/bewerkdatum en bestaande open/goedgekeurde issue; alleen
   geverifieerde gegevens van GitHub REST. Verifieer preflight en de echte
   `main` immutable, geen optionele hash- of scopevelden. Test verkeerd
   issue, herhaalde verwerking, oude goedkeuring, vervalst artifact,
   andere SHA en concurrerende goedkeuringen. Nog geen dispatch.
2. **B3.2: eenmalige gesloten dispatch.** Pas na aparte expliciete review:
   voeg een strikt begrensde trigger en minimaal benodigd `actions:write`
   toe **alleen aan de dispatchlaag**, nooit aan B1/B2 of de writer. Zorg
   voor server-side eenmaligheid op issue-ID én comment-ID door duurzame
   state vóór dispatch met een atomische claim of aantoonbaar equivalent.
   Alleen een workflow-run-id/uitkomst uit de GitHub Actions API geldt als
   bewijs van dispatch. Bij timeouts/onzekere externe antwoorden: niet
   automatisch opnieuw dispatchen; eerst zoeken naar bestaande run.
   Sluit of annuleer geen issue vóór succesvolle autorisatieverificatie;
   log geen token of baselines buiten strikt benodigde bewijsgegevens.
3. **B3.3: smoke-test in veilige modus.** Test offline/fake GitHub API
   inclusief twee gelijktijdige events en race op `main`, en daarna
   een read-only productiegerelateerde dry-run zonder workflow_dispatch
   naar de SFTP-writer. Eerste echte deployment blijft uitsluitend mogelijk
   na afzonderlijke volledige productieautorisatie voor release-ID,
   exacte immutable main SHA, bestandspaden, remote SHA/ABSENT en rollback.

## Beslisbare veiligheidsvoorwaarden vóór B3.2-merge

- **Geen extra autorisatieweg:** een issue, label, CI, PR-merge, akkoord in
  chat of metadata-artifact mag niet op zichzelf een deployment starten.
- **Geen replay:** hetzelfde comment/event/releaseverzoek kan hoogstens
  één keer de gedeelde workflow starten, ook bij GitHub-redelivery,
  retries, gelijktijdige jobs of API-fouten.
- **Geen privileged observer:** de bestaande B1/B2-workflows houden
  read-only rechten. Secrets en OVH credentials blijven uitsluitend bij
  de bestaande productieomgeving.
- **Fail-closed:** onbekende of incomplete preflight, workflow, bronhash,
  remote baseline, tijd, actor of manifest => geen dispatch.
- **Bewijsgrens:** succesvolle workflow_dispatch alleen is geen succesvolle
  deployment; B1/B2 lezen latere job/artifactstatus en browseracceptatie
  is afzonderlijk.
- **Geen omweg:** voeg geen push-, label- of ongecontroleerde
  issue_comment-trigger toe aan `shared-ovh-release.yml` zelf.

## Beslissing

Dit document autoriseert **geen** merge, automatische workflowstart of
productiedeployment. Het is een toetsbare ontwerpbasis voor B1 en volgende
afzonderlijk goedgekeurde PR's.
