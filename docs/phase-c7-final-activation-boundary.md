# Fase C7 — finale offline beveiligingsreview en activeringsgrens

**Status: uitsluitend voorbereiding. Geen live writer, geen nieuwe GitHub-rechten.**

## Doel

Fase C1–C6 leveren read-only CI-diagnose, een deterministisch
correctievoorstel, SHA-256/Git-blob-evidence, GitHub-PR/branch/blob-binding
en twee opeenvolgende GET-passes. C7 integreert de ontbrekende
beveiligingsvoorwaarden in een **niet-uitvoerbare review**:

- Bind de bronrapporthash, PR-nummer, head-SHA, PHP-testpad en kandidaatblob
  aan één exact *review-only* issuecommentaar van de vaste repository-eigenaar.
- Valideer de structuur van GitHub App-installatie, repositoryselectie en
  verwachte minimale inhoudsrechten tegen de bestaande installatie.
- Controleer aangeleverde branch-/tag-rulesets op actief beleid, expliciete
  anti-verwijdering/anti-forcepushregels en afwezigheid van bypass-actoren.
- Vereis een **nieuw** issue na de beëindigde C2-proef; de nonce van issue
  #427 mag nooit opnieuw worden gebruikt.
- Weiger tegenstrijdige of ontbrekende gegevens; lever anders alleen
  `READ_ONLY_SECURITY_REVIEW` met expliciete openstaande verplichtingen.

`scripts/development_ci_c7_activation_review.py` is pure Python, offline,
en **wordt niet door Actions aangeroepen**. Zowel een DENY als een volledig
passend reviewrecord bevat `can_write=false`, `activation_allowed=false`,
`can_merge=false`, `can_dispatch=false`, `nonce_reserved=false`
en `git_ref_updates_executed=0`. Een passende review is **geen bewijs** dat
de GitHub API-antwoorden onafhankelijk geauthenticeerd, actueel of atomisch
waargenomen werden. De reviewcommenttekst bevat bewust
`PHASE-C-REPAIR-REVIEW-ONLY`; deze tekst is **geen live-uitvoerautorisatie**.

## Wat nog noodzakelijk is voor live, herbruikbare fase C

1. Een strikt geïsoleerde **trusted writer-executor** ontwerpen, onafhankelijk
   securityreviewen en volledig testen, die geen onbetrouwbare PR-code uitvoert.
2. Vlak vóór de eerste mutatie onafhankelijke, verse, geauthenticeerde GETs
   verzamelen voor eigenaar, issue/comment, App, installatie, actieve
   branch/tagregeltoepassing, oude blob en PR-head. De vaste Ruleset-ID op
   zichzelf bewijst niet dat de regel effectief op de doelbranch van toepassing is.
3. Een **nieuwe**, exacte, eenmalige eigenaarautorisatie voor het voorgestelde
   live herstel krijgen: repository, PR, doelref, issue, immutable head,
   oorspronkelijke blob, nieuwe blob, nonce, App en werkelijk toegestane actie.
   Het booleaanse `author_approved` in de offline policy is geen bewijs.
4. Een duurzame, werkelijk racebestendige nonceclaim onder effectief
   beschermde tagregels gebruiken; de update moet de exacte oorspronkelijke
   branch-head bewaken (geen force-push, geen tweede poging bij onbekende
   transactie-uitkomst). De eerdere C2-nonce is **verbruikt**.
5. Na de eenmalige poging afzonderlijk de branch, nonce, bestandsblob,
   alle nieuwe CI-jobs en de volledige commitwijziging teruglezen, zonder
   automatisch te mergen of deployen.
6. Pas na een afzonderlijke exact gescopeerde **activeringsautorisatie**
   een schrijvende GitHub App-sessie toelaten. De bestaande productiecontroller,
   OVH, databases, releasegates en overige branches blijven buiten scope.

**Harde grens:** PR #426 blijft open/draft en mag nooit gemerged of opnieuw
gemuteerd worden. Ook `go`, een groene CI of deze review zijn geen
autorisatie voor een writer of productieactie.

## Acceptatie van deze C7-PR

Uitsluitend offline code en tests, zonder secrets, workflows, GitHub-API
mutaties of sandboxwrites. Minstens één strikt positieve reviewfixture en
negatieve tests voor gewijzigde head/bronhash, vervalste issue-/commentactor,
andere App-installatie, extra rechten, ruleset-bypass, ontbrekende
regels, verbruikte of onzekere nonce en tegenstrijdige resultaten. Groene
PR-head-CI, afzonderlijke mergeautorisatie en nieuwe main-push-CI vereisten.
