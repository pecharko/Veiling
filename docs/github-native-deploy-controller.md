# GitHub-native deploymentcontroller — standaardwerkwijze

De bestaande controller is `.github/workflows/verified-deploy-controller.yml` met
`scripts/verified_deploy_controller.py`. Dit is de **enige** issue-gestuurde
uitvoerder. Maak geen tweede productiecontroller en schakel geen automatische
productie-upload bij `push`, `merge` of groene CI in.

## Veilige keten voor toekomstige releases

1. Merge de goedgekeurde PR pas na alle drie vereiste groene checks op de exacte head SHA.
2. Vereis een afgeronde **volledige** read-only OVH-preflight op de actuele main-SHA,
   inclusief uitgevoerde jobs, pinned hostkey, remote SHA-256-manifest en artifact.
3. Presenteer aan de gebruiker de exacte SHA, vierbestanden-allowlist, preflight-run,
   remote hashes, backup/rollbackprofiel en downtime-risico. Vraag een **afzonderlijke
   expliciete definitieve productieautorisatie**.
4. Pas na die autorisatie: maak met de geautoriseerde GitHub-connector een NIEUW
   issue aan, met titel `DEPLOY-AUTH: <release>`, eigenaar als auteur, en het
   bestaande label `approved-deploy-request`. Gebruik de exacte actuele SHA.
   Plaats vervolgens met de GitHub-connector een issue-comment met exact dit formaat,
   zonder Markdown of extra regels:

```text
DEPLOY KAVELNUMMER THUMBNAILS WITH ROLLBACK
release_sha=<exacte main SHA>
preflight_run_id=<preflight run ID>
expected_kavel=<remote hash admin/kavel.php>
expected_lot_form=<remote hash private/lot_form.php>
expected_css=<remote hash admin/admin.css>
expected_index=<remote hash admin/index.php>
```

5. De bestaande `issue_comment`-workflow valideert issue-auteur, commentaarauteur,
   label, issuehistoriek, exacte main-SHA, run, maximaal 24 uur oud artifact, schema
   en **alle vier** remote hashes. Hij sluit het issue **voor** eenmalige dispatch
   en gebruikt de bestaande `kavelnummer-thumbnails-release.yml`.
6. Controleer controller-run, dispatch-run, bestanden, checksumverificatie,
   HTTPS-smoketests en het zevendaagse rollbackartifact; meld fouten direct.
   Bij mislukte dispatch na verbruikt issue: **nooit hetzelfde issue hergebruiken**.

## Grenzen en implementatievoorwaarden

- Een algemene gebruikersopdracht `deploy` is geen definitieve toestemming.
- Een skill kan GitHub-issues/comments via de connector voorbereiden, maar de
  GitHub Actions-controller voert de dispatch uit. Het skillbestand zelf is
  geen achtergrondproces.
- Als de connectorcomment niet onder de repository-eigenaar wordt geplaatst,
  **niet** proberen de identity guard te omzeilen: laat de eigenaar zelf het
  comment plaatsen en controleer de controllerjob.
- Deze controller ondersteunt momenteel **uitsluitend** de vierbestandenrelease
  van `kavelnummer-thumbnails-release.yml`. Voor andere allowlists is eerst een
  afzonderlijk beoordeelde controllerwijziging en releaseflow nodig.
- Niet zomaar eerdere chattoestemmingen opnieuw inzetten om na een gewijzigde
  main-SHA of verlopen preflight een productie-upload te starten.
- Geen geheimen, tokens of SSH-sleutels in issues/comments of chat.

## Verifiëren zonder productie-upload

Gebruik `.github/workflows/controller-authorization-dryrun.yml` met een echte,
actuele geslaagde preflight-run. Deze test controleert het bewijs en een
synthetische goedkeuring **zonder** issuecomment, issuemutatie of dispatch.
