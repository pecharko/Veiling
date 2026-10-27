# B3.2 — duurzame eenmalige autorisatie: implementatiekeuze

Status: **ontwerp, niet-uitvoerend**. Baseline `main`:
`b0e25850113bce80d7fa27e1296f9b1005617512`.

## Probleem

`scripts/controller_one_shot_claim_contract.py` controleert momenteel slechts
een puur offline claimcontract en retourneert altijd
`dispatch_allowed=False`. GitHub Actions `concurrency` is geen
duurzame eenmaligheidsregistratie: een workflow kan opnieuw worden aangeboden
of opnieuw gestart na het vrijgeven van een concurrency-groep.

## Conservatieve aanbevolen keuze

**Aparte GitHub Git-ref als exclusieve claim, via atomische create-ref
(create-only; HTTP 422 bij bestaande ref).** Een ref onder een gereserveerde,
niet-deployende namespace (bijv. `refs/heads/deploy-claims/<digest>`) fungeert
uitsluitend als durable ledgerentry. De digest komt van een canonieke serialisatie
van repository + issue-ID + comment-ID + release-ID + exacte main SHA.
Een Git-ref kan naar een bestaand immutable commitobject wijzen; de claim hoeft
geen commit, branchworkflow of codebestand te creëren.

**Voorwaarden voordat deze optie uitvoerbaar mag worden gebouwd:**

1. Bewijs dat de GitHub API create-ref inderdaad atomisch en exclusief is bij
   gelijktijdige requests. Een preflight-GET gevolgd door een aparte POST
   zonder unieke create-only garantie is ongeschikt.
2. Verbied delete/force-update van de claimnamespace, of ontwerp expliciete
   server-side safeguards. Als een actor de ref kan wissen en opnieuw aanmaken,
   is de claim **niet** duurzaam voor replaypreventie. Branch protection/rulesets
   en GitHub App-permissies vereisen afzonderlijke veiligheidsreview.
3. Gebruik alleen de strikt noodzakelijke `contents:write` in een afzonderlijke
   dispatchlaag; laat de bestaande writer en B1/B2 `read`-only.
4. Leg in auditrapporten issue-ID, comment-ID, release-ID en exacte SHA vast.
   Voor daadwerkelijk dispatch eerst nogmaals eigenaar, commentstatus,
   24-uursgeldigheid, main SHA, manifest, source hashes en remote baseline
   controleren via bestaande `review_owner_event_dryrun`.
5. **Volgorde:** een exclusieve claim vóór dispatch betekent dat een crash
   ertussen een release definitief kan blokkeren. Dat is veiliger dan dubbel
   deployen; automatische herstart is verboden. Handmatige herstelprocedure
   vereist afzonderlijk bevoegde audit en een nieuw autorisatietraject.
6. Bij timeout van create-ref is de uitkomst **UNKNOWN**. Doe geen nieuwe
   create-ref of dispatch; verifieer de ref via een afzonderlijke GET. Een
   gevonden ref bewijst een claim, **niet** dat dispatch gestart is.
7. Bij timeout van workflow_dispatch: status `DISPATCH_UNKNOWN`; zoek
   onafhankelijk naar een passende Actions-run. Zonder betrouwbare
   uniek identificeerbare run geen tweede dispatch.
8. Geen ref-creatie of workflow_dispatch vanuit pull requests, labels of
   gewone comments zonder geverifieerde ownerautorisatie. Het bestaande
   `shared-ovh-release.yml` blijft uitsluitend met handmatige dispatch en
   expliciete confirmation werken.

## Nog niet veilig bewezen

- GitHub refs zonder write-permissie kunnen niet als ledger worden aangemaakt.
- Een branchref is niet vanzelf onwisbaar; replaybeveiliging is afhankelijk van
  beschermingsbeleid en een bewezen vertrouwensmodel.
- Een lege in-memory set, Actions concurrency, issues sluiten, labels of
  uitsluitend idempotency-key in de workflowinput bieden geen exclusieve CAS.
- De GitHub workflow_dispatch API geeft geen gegarandeerd uniek run-ID terug.
  Een 2xx-response alleen is dus onvoldoende als onafhankelijk bewijs van
  één effectieve uitvoering.

## Testmatrix voor de volgende afzonderlijke code-PR

- Twee gelijktijdige create-only claims voor identieke canonical key:
  precies één kandidaat kan winnen; verliezer mag **nooit** dispatchen.
- Redelivery van dezelfde issue/comment, gewijzigde SHA, verkeerd owner,
  verlopen of gewijzigd comment: fail-closed.
- Timeouts vóór/na claim en vóór/na dispatch; elke onbekende uitkomst
  blokkeert herhaalde dispatch.
- Gevonden claimref met onjuiste target SHA: BLOCKED.
- Ref ontbreekt na onzekere create-uitkomst: nog steeds geen automatische
  herpoging zonder externe beoordeling.
- Replay na zeven dagen, na sluiten issue, na merge of reboot blijft geblokkeerd.
- Geen productie-deploy, workflowstart of branchwrite in offline tests.

## Veiligheidsgrens

Dit ontwerp geeft **geen** toestemming voor GitHub refs te schrijven, nieuwe
rechten of secrets toe te voegen, workflows te wijzigen, automatisch dispatchen
of OVH-productie te wijzigen. B3.2 werkt na PR #277 nog steeds alleen offline.
De gedeelde production writer en alle bestaande autorisatiegates blijven
ongewijzigd. De daadwerkelijke CAS-/dispatch-koppeling vereist aparte
scope-, risico- en exacte commitgoedkeuring.
