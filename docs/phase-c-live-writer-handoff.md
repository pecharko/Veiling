# Fase C — definitieve live-writeroverdracht (READ-ONLY)

**Status:** alle offline bouwstenen C1–C8 op main; toekomstige uitvoering nog
**niet ontworpen of geautoriseerd als uitvoerbare live writer**.

Deze overdracht voegt twee herbruikbare, strikt niet-uitvoerbare controles toe:
`scripts/development_ci_live_writer_handoff.py` verifieert de
**voorwaardenstructuur** van branch/tag-rulesets voor de exacte toekomstige
sandboxbranch en het specifieke nieuwe nonce-tagpatroon; dezelfde module
beoordeelt na een toekomstige poging de structuur van aangeleverde
branch-, nonce-, commit- en inhoudsreadbacks. Beide zijn fail-closed en
bewijzen noch herkomst noch atomiciteit.

## Technisch geverifieerde uitgangspositie op 30 oktober 2026

- `main` na PR #472: `b1d8b1267b8c05d8bd85183d797aec06b1b2bd1e`.
- PR #426 blijft **open en draft**, en mag niet gemerged worden.
- Nonce `refs/tags/phase-c-once-issue-427` bestaat; **nooit hergebruiken**.
- Branch-ruleset #26113993: `deletion`, `non_fast_forward`, actief,
  zonder bypass, maar het `include`-patroon dekt uitsluitend
  `refs/heads/test/phase-c-writer-fixture-20261029`.
- Tag-ruleset #26113833: `update`, `deletion`, actief, zonder bypass;
  `include` is `refs/tags/phase-c-once-issue-*`.
- De GitHub App-claims uit offline fixtures zijn **geen bewijs van huidige
  tokenbevoegdheden** of een nieuw uitgegeven vertrouwd token.
- De CI-feedbackworkflow blijft `actions: read` en `contents: read`.

Een nieuwe branch valt dus **niet** onder de huidige branchespecifieke
regels. Dat is een harde blokkade voor een nieuwe live test. Deze PR
**wijzigt de rulesets niet** en vervangt de vereiste expliciete
wijzigingsautorisatie niet.

## Eén gescopeerde live-activering — te beslissen na aparte review

1. Kies één **nieuwe** geïsoleerde `test/phase-c-*`-branch met één
   vooraf aangemaakt onschuldig PHP-testbestand, plus een aparte draft-PR
   en afzonderlijk door de eigenaar aangemaakt autorisatie-issue.
   Nooit de bestaande C2-fixture of issue #427 als nieuwe sandbox gebruiken.
2. Bevestig het exacte branchref, immutable oorspronkelijke head,
   oorspronkelijke Git-blob en volledige kandidaatinhoud plus kandidaat-
   blob-SHA en SHA-256. Weiger een tweede bestand of veranderde base.
3. Leg een plan voor **exacte doelbranchbescherming** voor. Voer geen
   rulesetwijziging uit zonder aparte toestemming op regels, ID, scope
   en alle actuele baselines. Controleer achteraf de werkelijke
   effectieve bescherming op die specifieke branch, niet alleen
   een positieve statische ruleset-structuur.
4. Controleer onafhankelijk via actuele, door de betrouwbare uitvoerder
   geauthenticeerde GitHub App- en REST-reads de eigenaar/issue/comment,
   App-installatie, repositoryscope, tokenrechten, PR/head, blob, rulesets
   en afwezigheid van de **nieuwe** nonce.
5. Ontwerp en review een afzonderlijke vertrouwde uitvoerder voor slechts
   één vooraf geautoriseerde atomische nonce-reservering +
   niet-force branch-head CAS. Geen `pull_request_target` met PR-code,
   tokens in CI-feedback of code uit onbeoordeelde branches; geen toegang
   tot OVH, database, releasecontroller of deploymentcredentials.
6. Vraag **één nieuwe exacte eigenaarautorisatie** voor de live actie,
   die de repository, issue, PR, head/blob/candidate, branch, nonce,
   installatiescope en maximaal één poging vastlegt.
   Een offline C7-reviewcomment of algemeen `go` is daarvoor nooit voldoende.
7. Voer alleen die geautoriseerde actie eenmaal uit. Bij timeout,
   onbekende uitkomst of drift: **niet herhalen**, eerst read-only
   incidentanalyse. Laat onafhankelijk de nieuwe nonce, ref, commit-parent,
   exact gewijzigde testpad, Git-blob en vervolgens afzonderlijke CI
   controleren. **Geen automatische merge of productiedeployment.**

## Veiligheidsgrens van deze PR

De functie `inspect_target_rule_coverage` kan enkel
`RULE_SHAPES_MATCH_REVIEW_ONLY` of `DENY` geven.
De functie `inspect_future_postwrite_readback` geeft enkel
`READBACK_SHAPE_MATCH_ONLY` of `DENY`.
`execute_one_shot` is onvoorwaardelijk geblokkeerd. Er zijn geen
tokens, netwerkverzoeken, GitHub-mutaties, noncecreatie, nieuwe rechten,
workflowtriggers, releases of productieacties.

Het is geen live uitvoeringsimplementatie. De fase C1–C8 offlineketen
en deze aanvullende read-only controles zijn technische voorbereiding.
**Volledig functioneel afgeronde fase C** vereist een onafhankelijke
code-/beveiligingsreview van de aparte writer, een expliciete nieuwe
activeringsautorisatie en een nieuw, succesvol geverifieerd éénmalig
sandboxexperiment. Niet eerder.



## Laatste C9-voorbereiding: één exact atomisch plan (geen writer)

De offline functie \`propose_inert_atomic_packet\` in de bestaande
\`development_ci_live_writer_handoff.py\` bouwt voor een **toekomstige**
nieuwe PR een strikt afgebakende \`updateRefs\`-envelop met:

- Eén nooit eerder gebruikte nonce-tag met \`beforeOid=000…000\`,
  \`afterOid=\` de oorspronkelijke commit en \`force=false\`.
- Eén sandboxbranch met \`beforeOid=\` de oorspronkelijke commit,
  \`afterOid=\` de exacte vooraf berekende kandidaat en \`force=false\`.
- Een vast repository-node-ID, gescheiden van productie en zonder
  automatische merge. De envelop wordt alleen als **gegevens** teruggegeven.
- Exacte koppeling aan een toekomstig owner-issue, comment-ID, de
  reviewertekst \`PHASE-C-LIVE-REVIEW-ONLY\`, de head/blob/candidate-SHA,
  de doelbranch, het testpad en de vastgepinde worker-SHA.

Deze reviewertekst mag **nooit als live-write autorisatie dienen**.
Een passende fixture is geen verificatie van commentaarauteur,
GitHub App-rechten, aanwezigheid van echte bescherming, ontbreken van
de nonce of atomische uitvoering. Er is geen token-, netwerk- of
mutatiepad in dit onderdeel.

### Veilige doelbranchbescherming zonder terugval

De op GitHub gecontroleerde ruleset #26113993 heeft momenteel uitsluitend
\`refs/heads/test/phase-c-writer-fixture-20261029\` als include.
De **vooraf te autoriseren wijziging** moet die oude include behouden
en exact één nieuwe beschermde sandboxbranch als tweede include toevoegen.
De offline checker aanvaardt alleen die twee exacte refs, geen wildcard
en geen wegvallen van de bescherming van de oude C2-branch.
Ruleset #26113833 met \`refs/tags/phase-c-once-issue-*\`,
\`update\` en \`deletion\` blijft ongewijzigd.

De wijziging van de GitHub-ruleset, het maken van een nieuwe testbranch,
het aanmaken van een nieuw owner-issue, het uitgeven van een nieuw token
en de live-transactie vragen **afzonderlijke, exacte toestemming**.
De huidige PR wijzigt géén ruleset en creëert géén sandbox.

### Echte live-writerautorisatiegrens

Een nieuwe, onafhankelijke trusted uitvoerder moet de toekomstige
\`updateRefs\`-envelop zelf opnieuw opbouwen uit verse,
geauthenticeerde GitHub- en App-GETs, een afzonderlijke **live-only**
ownerautorisatie verifiëren en pas na alle checks een enkele mutation
uitvoeren. De huidige lokale C2-worker is hardgecodeerd voor de al
verbruikte #427-nonce en mag niet hergebruikt worden. Bij timeout of
onduidelijke uitkomst blijft een tweede poging verboden. Daarna volgt
onafhankelijke readback inclusief wijzigingen op precies één PHP-testpad,
nonce-status en nieuwe CI. Geen OVH of productie.
