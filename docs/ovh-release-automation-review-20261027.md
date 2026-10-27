# Minder handmatige interacties bij OVH-releases

Status: **ontwerp ter review; geen geactiveerde nieuwe deployroute**.

## Vastgestelde situatie (27 oktober 2026)

- `.github/workflows/shared-ovh-auto-manifest.yml` maakt een read-only SFTP-baseline en een manifestbranch, maar `gh pr create` kan bij de bestaande repositoryrechten falen ondanks een succesvolle branch-push (o.a. Website Beheer en checkbox).
- `.github/workflows/shared-ovh-release.yml` accepteert alleen `workflow_dispatch` op `main`, met `release_id` en `DEPLOY-<id>-<main SHA>`. De huidige ChatGPT-GitHub-connector heeft geen tool voor `workflow_dispatch`; dat los je niet op door instructies in een skill.
- De writer controleert main, manifest-source-hashes, remote drift, hostkey-pin, backup, rollback en per-bestand post-upload readback. **Behoud dit ongewijzigd**.
- Er wordt geen machineleesbare, aan de run gekoppelde deploymentattestatie beschikbaar gemaakt die veilig en zelfstandig door de connector kan worden opgehaald. Daardoor vraagt de gebruiker handmatig `groen`.
- De OVH WAF kan GitHub-hosted HTTPS probes met 403 blokkeren. Dat is geen geslaagde browseracceptatie en ook geen bewijs van website-uitval.

## Gewenst eindproces

1. ChatGPT reviewt de wijziging en laat code- en manifest-PR's met drie passende groene CI-checks volgens mergebeleid doorlopen, zonder extra `go`.
2. Na een echte read-only SFTP-baseline bevat de finale goedkeuring exact release-ID, immutable main SHA, bestandspaden, bron- én remote-SHA, back-up en rollback.
3. **Alleen de expliciete, specifieke finale goedkeuring** opent een eenmalige, aantoonbaar gemachtigde dispatchroute; een gesprekstekst, issuecomment, label, PR-merge of repository-push mag dit **niet zelfstandig triggeren**.
4. De bestaande productie-writer controleert onmiddellijk vóór upload opnieuw main/SHA/remote drift. Bij mismatch fail-closed, nul writes.
5. De workflow schrijft een machineleesbare status/attestatie met release-ID, main SHA, exacte paden, source hashes, baseline hashes, geverifieerde readback hashes, uitvoeringstijd, run-ID, rollbackartifact-ID, eindstatus, failure phase en beschikbare herstelstatus. Geen secrets en geen onnodige persoonsgegevens.
6. Na bewijscontrole vraagt ChatGPT slechts de menselijke functionele browseracceptatie en registreert die éénmaal in bestaande PR/issue.

## Implementatie in afzonderlijke, gereviewde PR's

### A. Manifest-PR-betrouwbaarheid (geen productie-write)
- Onderzoek de repositoryinstelling voor bot-PR-aanmaak; controleer of `GITHUB_TOKEN` daadwerkelijk `pull-requests: write` mag gebruiken en of GitHub Actions PR's mogen openen.
- Onderzoek een **reviewbare** fallback (bijv. `gh pr create` retry of een apart least-privilege GitHub App-token); geen tokens, instellingen of permissies verhogen zonder afzonderlijke securityreview.
- Zorg dat de bestaande branch met SFTP-bewijs bij ontbrekende PR intact blijft; geen duplicaatruns en geen fictieve baselines.

### B. Machineleesbare attestatie (zonder minder strenge deploymentgate)
- Schrijf resultaten pas na echte sourcecheck, pre-write baselinecheck, upload en post-upload readback. Onderscheid `VERIFIED`, `FAILED`, `ROLLED_BACK`, `ROLLBACK_FAILED` en `NOT_STARTED`.
- Publiceer het bestand als GitHub Actions-artifact met beperkte retentie, liefst beschikbaar ook na failure; behoud bestaande zeven dagen rollbackartifact.
- Laat een onafhankelijke read-only lezer het artifact aan workflow-ID, releasecommit en file allowlist binden. Een groen workflowlabel zonder de benodigde bewijselementen is geen zelfstandige verificatie.
- Voeg regressietests toe voor drift, gedeeltelijke write, uploadfout, backupfout en onvolledige rollback.

### C. Eenmalige goedgekeurde dispatch
- Ontwerp een controller die bevoegdheden verifieert en een **eenmalig gebruikte autorisatie** cryptografisch of via een beschermde omgeving koppelt aan exact release-ID + immutable commit + manifest en remote hashes.
- Gebruik GitHub App/afgeschermde Actions-scope met minimale `actions: write`-rechten; geen generieke GitHub-API-writer in ChatGPT.
- Vereis protected environment review waar beschikbaar; `main` en alle bestandshashes moeten opnieuw overeenkomen wanneer de writer start.
- Zet nooit een trigger op een door anderen wijzigbare PR, issuecomment, label, tag of willekeurig bestand. Geen auto-deploy door merge.
- Faal gesloten op replay, meerdere dispatches, verlopen approval, main drift, manifestverschil, onbereikbare remote of ontbrekende rollbackmogelijkheden.

### D. Acceptatie en afsluiting
- Noteer machinebewijs afzonderlijk van menselijke browseracceptatie; gebruik de bestaande manifest-PR en eventueel bestaande issue, geen administratieve PR.
- Beperk handmatige stap tot ontwerpacceptatie (waar nodig), definitieve finale productieautorisatie en browseracceptatie. Geen `groen` en geen run-ID vragen wanneer bewijs beschikbaar is.

## Review- en acceptatiecriteria

- Onderbouw eerst welke GitHub-authenticatie/connector in deze omgeving werkelijk beschikbaar is; claim geen dispatch die niet getest is.
- Actionlint, contracttests, geïsoleerde failure/rollbacktests en drie repo-CI-checks groen op iedere security-/controller-PR.
- Bewijs een volledige dry-run/testrelease in een **niet-productieomgeving** met fictieve host/secret en strikt beperkte rechten.
- Geen wijziging aan productie-secrets, permissions, cron, database, OVH-runtime of gedeelde releasewriter zonder nieuwe, expliciete scopegebonden goedkeuring.
- **Deze ontwerp-PR mag geen productieactie veroorzaken, niet automatisch gemerged worden en is geen goedkeuring van toekomstige implementatie of deployment.**
