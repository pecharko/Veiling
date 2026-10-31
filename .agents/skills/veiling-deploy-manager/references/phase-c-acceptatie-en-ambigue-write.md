# Fase C: sandboxacceptatie en ambigue writerrespons (afgesloten 31 oktober 2026)

## Status en scope

**TECHNISCH AFGEROND ALS GEÏSOLEERDE SANDBOXPROEF; NIET ALGEMEEN GEACTIVEERD.** Dit is een historische reconstructie, geen nieuwe of doorlopende autorisatie. De bestaande productie-, OVH-, database-, rollback- en releasecontrollergrenzen blijven volledig van kracht. Aansluitend werk aan een autonome codecorrector is een **nieuwe ontwikkelopdracht**, geen restant van de oude issue-autorisatie.

- Repo `pecharko/Veiling` / afgesloten [issue #482](https://github.com/pecharko/Veiling/issues/482), technisch [eindverslag #6540160576](https://github.com/pecharko/Veiling/issues/482#issuecomment-6540160576) (`state=closed`, `state_reason=completed`).
- Beveiligingsfix [PR #481](https://github.com/pecharko/Veiling/pull/481) als squash-commit `c7def8fb3ad1bd8d4b00a7adbe0a1543ee5856ce` op `main`, na 19/19 offline unittests (lokale door eigenaar aangeleverde stdout), drie succesvolle PR-workflows en succesvolle onafhankelijke main-push-CI #41176024347. **Historische SHA, altijd actuele main opnieuw ophalen.**
- Proefdoel was **uitsluitend** de beschermde ref `refs/heads/test/phase-c-new-isolated-20261030`, met draft [PR #475](https://github.com/pecharko/Veiling/pull/475) ongemergd. Historische draft PR #426 eveneens ongemergd. Exact één doelbestand: `tests/phase_c_new_repair_test.php`.
- Oud sandbox-head `2ee5419b4642b2108414c8b9d4f193298004b23a`; nieuwe head `73be8a820e0801c9ceba3b548da91a5732325ec4`; enige parent van de nieuwe commit is de oude head. De commit-compare was exact één commit vooruit, nul achter, één bestandswijziging, één insertie en één deletie. Alleen `return flase;` is gecorrigeerd naar `return false;`.
- Nieuwe blob `3f48693b3092785554192ad766940565c9435a17`, inhouds-SHA-256 `b743ff7e7006659676f24bf7626d95cb8a75a52b4c681e74a2629924d221059d`.
- Eenmalig eigenaar-issue #482, [comment #6540077530](https://github.com/pecharko/Veiling/issues/482#issuecomment-6540077530) en nonce `refs/tags/phase-c-once-issue-482` **verbruikt**. GitHub-readback: nonce wijst naar oude head `2ee5419b4642b2108414c8b9d4f193298004b23a` en branch wijst naar de kandidaat. Eveneens nooit issue #480 / comment #6539747796 hergebruiken; deze eerdere poging was afzonderlijk verbruikt.
- CI op de nieuwe sandbox-head `73be8a...`: [PHP/repository #41176890015](https://github.com/pecharko/Veiling/actions/runs/41176890015), [kavelintegratie #41176889989](https://github.com/pecharko/Veiling/actions/runs/41176889989), [snapshot/herstel #41176889988](https://github.com/pecharko/Veiling/actions/runs/41176889988); alle drie workflows succesvol en vijf concrete jobs geslaagd.

## Essentiële les: lokale uitvoer is niet hetzelfde als de remote eindtoestand

De **letterlijke lokale workerrespons** luidde:

```json
{"decision":"AMBIGUOUS_STOP_NO_RETRY","executed":true,"retry_allowed":false,"readback_confirmed":false}
```

Hierna bleek via **onafhankelijke GitHub GETs** dat de exacte toegestane branch én nonce waren bijgewerkt, de nieuwe commit-parent/diff/blob correct waren, PR #475 draft/ongemergd bleef en CI op de nieuwe sandbox-head slaagde. Het exacte beoogde **remote resultaat** is dus technisch bevestigd. Dit maakt de oorspronkelijke onzekere **lokale receipt** niet alsnog `READBACK_CONFIRMED`. De oorzaak van de lokale rapportageafwijking is niet vastgesteld; behandel deze alleen via afzonderlijke **offline** diagnose en tests, zonder live replay.

**Protocol bij toekomstige ambigue schrijfresultaten:**

1. Bij `executed=true`, timeout, ontbrekende ontvangstbevestiging, `AMBIGUOUS_STOP_NO_RETRY` of onzekerheid onmiddellijk stoppen. **Geen retry**, ook niet wanneer er niets op GitHub gewijzigd lijkt. Eénmalige autorisatie en nonce blijven verbruikt.
2. Onafhankelijke (niet-writer) GitHub-readback: actuele main en scope; beide relevante refs met commit-ID; exacte commitparent en vergelijking; pad, originele/nieuwe Git-blobs en SHA-256; PR draft/merge-status; toepasselijke checks/jobs op de juiste head-SHA; rulesets en effectieve bypass; controleer eventuele afwijkingen of timing expliciet.
3. Houd twee bewijsvelden gescheiden: `local_attempt_result` (letterlijke status, mogelijk ambigue) en `remote_post_state` (`verified`, `not_verified` of `partial`). **Rapporteer geen succesvolle writerreceipt als de lokale uitvoer dat niet bewijst.** Evenmin automatisch falen van de remote mutatie afleiden uit een ambigue receipt.
4. Leg technisch eindverslag en CI-links vast binnen **expliciet toegestane** issue-/PR-scope. Sluit of muteer een issue niet zonder bijbehorende autorisatie. Sluit geen test-PR's of beschermde refs stilzwijgend.
5. Gebruik voor nieuw schrijfwerk een **nieuwe** exact gescopeerde toestemming, nieuw authentiek owner-issue/comment en een ongebruikte nonce. Een geslaagde readback verleent hiervoor geen toestemming.

## GitHub App-rulesetveldredactie en fail-closed controle

Bij GitHub REST `Get a repository ruleset` kan `bypass_actors` uit de respons ontbreken voor een token zonder ruleset-schrijfrechten. Bron: https://docs.github.com/en/rest/repos/rules#get-a-repository-ruleset . De bestaande App-installatie had bewust alleen `contents:write` en `metadata:read` voor uitsluitend de repository `Veiling`; voeg geen administration/ruleset-write-rechten toe om dit te verhelpen.

De fix in `scripts/phase_c_new_local_one_shot_worker.py` op historische main-commit `c7def8fb...` scheidt **owner** en **writer**: de owner haalt beide rulesets volledig op met expliciet `bypass_actors: []`, active enforcement, exact matching ref-filters/ruletypes en `current_user_can_bypass=never`. Alleen wanneer deze verse owner-proof is geslaagd, mag de daadwerkelijke beperkte installation writer-respons uitsluitend het veld `bypass_actors` **weglaten**. Indien dat veld aanwezig is, moet het exact `[]` zijn. Ontbrekende of `null` effective-bypass, zichtbare bypass, 403, andere regels, source/target/ref-drift of ontbrekende owner-proof leiden tot `DENY`. Na de writerproof volgen de onafhankelijke ref- en absent-noncecontroles. Nooit fallback, permissie-escalatie, automatische tokenaanmaak buiten de expliciete one-shot-context of twee-fasen-non-atomische vervanging.

De historische rulesets waren branch **#26113993** (`deletion`, `non_fast_forward` voor de twee exacte sandboxbranches) en tag **#26113833** (`update`, `deletion` voor `refs/tags/phase-c-once-issue-*`), actief zonder bypass. Historische rule-ID's bewijzen **geen** actuele bescherming; altijd bij een volgende autorisatie beide opnieuw authentiek controleren.

## Verdergaan na fase C

- Deze proef heeft de technische haalbaarheid van één streng afgebakende GitHub-refcorrectie aangetoond; **niet** de live werking van een generieke automatische ontwikkelagent of productie-deploycontroller.
- Ontwikkel en test eventuele betere writerresultaatverwerking eerst **offline**; nieuwe ontwikkelbranch/PR, toepasbare CI, review en afzonderlijke security-mergeautorisatie waar vereist. Geen hergebruik van de gesloten proef.
- Voor een zelfstandige ontwikkelworkflow: nieuwe opdracht, least-privilege, expliciete kandidaat/PR-scope en gecontroleerde autorisatiegates. Code-, ruleset- of workflowcorrecties mogen niet automatisch worden omgezet in production access.
