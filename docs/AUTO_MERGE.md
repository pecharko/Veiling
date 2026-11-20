# PR-merges: automatische CI-merge uitschakelen

## Incident en besluit (20 november 2026)

De historische workflow `.github/workflows/controlled-auto-merge.yml` mergeerde PR
[#641](https://github.com/pecharko/Veiling/pull/641) na groene PR-CI als
`github-actions[bot]` met `merge_method: merge`, **zonder afzonderlijke
exacte eigenaarautorisatie voor die automatisering**. De resulterende commit
`c064037cdcf5bbff737a086b80af5da886aa1204` had twee parents, hoewel
uitsluitend een squash-merge was toegestaan. Een onafhankelijke `main`-push-CI
werd niet uitgevoerd omdat de botmerge via `GITHUB_TOKEN` geen nieuwe
`push`-workflows activeerde.

**Beveiligingswijziging:** de onbewaakte `controlled-auto-merge.yml` wordt
verwijderd. Zolang de beveiligings-PR niet is samengevoegd, blijft het oude
mergepad op `main` actief. Er komt **geen vervangende automatische schrijver**.

## Verplichte mergebeslissing

- Vraag **per PR** een afzonderlijke expliciete eigenaarautorisatie met
  exact PR-nummer, immutable head SHA, onveranderde `main`-baseline,
  volledige bestandsallowlist, geslaagde toepasselijke CI en uitsluitingen.
- Verifieer direct vóór uitvoering opnieuw de actuele PR-status, beide SHA's,
  diff, conflictstatus, CI en bots die tussentijds kunnen hebben gemerged.
- Merge uitsluitend via een bevoegd geauthenticeerde actie met
  `merge_method=squash` en `expected_head_sha`; nooit via gewone
  `merge` of onbewaakte `workflow_run` na een groene CI.
- Controleer de echte GitHub-mergecommit na de actie: precies **één parent**
  (de vooraf geautoriseerde `main`), juiste gewijzigde bestanden en
  afzonderlijke werkelijke `event=push`-CI op de nieuwe SHA.
- Bij een ongevraagde merge, ander mergetype of ontbrekende CI: **STOP /
  NO RETRY**. Geen force-push, revert, tweede merge of workflowdispatch
  zonder een nieuwe, specifiek geautoriseerde procedure.

## Regressiebeveiliging en grenzen

`tests/no_unattended_merge_test.py` draait voortaan in
`.github/workflows/php-lint.yml` op PR's en `main`. Hij weigert het oude
workflowpad en bekende directe mergeaanroepen in GitHub Actions-workflows.
Dit is een **offline detectietest**, geen GitHub-autorisatiecontrole of
bescherming tegen alle denkbare andere scripts, externe Apps of handmatige
merges.

Bij deze controle was `main` **niet beschermd** via branch protection.
De repository laat GitHub-mergemethoden technisch toe. Een afdwingbare
regelsysteem-/branchbescherming vereist een **afzonderlijk eigenaarbesluit**
en valt buiten deze beveiligings-PR. Wijzig ook geen repositoryinstellingen,
permissions, secrets, OVH of productie vanuit deze PR.

Herinschakelen van automatisch mergen mag alleen na aparte securityreview
met echte exact gebonden eigenaarautorisatie, alleen squash, race- en
replaybeveiliging en onafhankelijk aantoonbare push-CI. Een label,
`low-risk`-allowlist of groene CI is op zichzelf nooit mergeautorisatie.
