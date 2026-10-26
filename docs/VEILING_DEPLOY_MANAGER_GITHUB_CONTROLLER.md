# Veiling Deploy Manager — controllergestuurde release (issue #200)

Gebruik bij een specifiek geautoriseerde release bij voorkeur de reeds geïnstalleerde `verified-deploy-controller.yml` in plaats van negen handmatige `workflow_dispatch`-velden. Dit is GitHub-native en wordt alleen actief na aparte securityreview en merge van PR #217.

## Veilig, eenmalig
- Bestaande controller: issue-comment only door repository owner op een open, door owner aangemaakt `DEPLOY-AUTH:` issue met label `approved-deploy-request`, zonder eerdere close in eventgeschiedenis.
- Autorisatie met `DEPLOY VERIFIED RELEASE`, exact geregistreerde workflow, goedgekeurde main SHA en preflight-run binnen 24 uur, vier exacte remote hashes, handmatige HTTPS-SHA, en expliciet door de gebruiker bevestigde schemamigratie.
- Controller verifieert de ongewijzigde SHA en echte preflight artifact, **sluit het issue voor dispatch** (one-shot/replaypreventie) en start via `gh workflow run`. Nooit automatisch opnieuw proberen na een onduidelijk dispatchresultaat.
- De workflow zelf hercontroleert SHA, schema- en HTTPS-bevestigingen, SFTP-hostkey, remote drift, backup, upload-readback en best-effort rollback. Na een succesvolle run blijft **handmatige post-acceptatie** vereist.
- Geen issue of comment namens de gebruiker simuleren; vraag om de noodzakelijke eigenaarshandeling als de connector geen issue-comment ondersteunt. Geen productie-upload op basis van een gewone chat-'go'.
- Een wijziging aan controller/registratie is security-sensitive en wordt **niet automatisch gemerged**.

## Goedkeuringscommentaar (exacte structuur)
```
DEPLOY VERIFIED RELEASE
workflow=issue-200-code-release.yml
release_sha=<exact-current-main-sha>
preflight_run_id=<successful-preflight-run-id>
expected_users=<64-character-remote-hash>
expected_login=<64-character-remote-hash>
expected_snapshots=<64-character-remote-hash>
expected_bootstrap=<64-character-remote-hash>
manual_https_precheck=VERIFIED-<exact-current-main-sha>
migration_confirmed=MIGRATED-admins.is_active
```

De genoemde SHA en preflight moeten **opnieuw** worden gecontroleerd na elke merge; eerdere productieautorisaties worden niet naar gewijzigde commits overgezet.
