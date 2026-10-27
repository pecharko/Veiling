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

## Security decision gate — whether Git refs are acceptable

**Current decision: NOT APPROVED for a writer.** Creating a branch ref is
insufficient until protection against deletion/force-push, bypass privileges
and actor separation is independently verified in the real repository.
GitHub branch naming and a successful create-ref response do not themselves
prove a permanent audit ledger.

Before any executable proposal, collect *read-only*, redacted evidence for:

| Gate | Required result | If missing |
|---|---|---|
| Atomic create-only | Same canonical key claimed by at most one contender | BLOCK |
| Ref immutability | No delete/force-update by dispatcher or ordinary writer token | BLOCK |
| Privilege separation | Claim writer cannot change repository protections or writer code | BLOCK |
| Recovery after crash | Claim persists after crash/restart; ambiguous dispatch is never retried | BLOCK |
| Durable audit identity | Issue/comment IDs + release ID/SHA + original approval remain auditable | BLOCK |
| Main/manifest freshness | Authorization adapter revalidates immutable main and reviewed hashes | BLOCK |
| Deployment independence | No change to existing SFTP drift gate, backups, rollback, browser acceptance | BLOCK |

### Deliberately NOT an exactly-once guarantee

Even an atomic immutable claim can guarantee at most *one attempted dispatch*
from a correctly constrained claim issuer. It does **not** guarantee a release
ran once, nor that GitHub accepted the request, nor that OVH changed.
The correct objective is **at-most-once attempt, fail-closed ambiguity**.
An unsuccessful or ambiguous dispatch may require a *new* explicit approval
after independent reconciliation. Never delete an old claim to retry.

### Threat scenarios to demonstrate before separate executable PR

1. Two runners claim the same key simultaneously: exactly one create-only
   operation wins; the loser cannot dispatch.
2. Runner crashes just after creating the claim: no second dispatch, even if
   no Actions run was started.
3. API times out after creating the ref: later readback may show the ref,
   but this does not authorize a replay or count as a dispatch.
4. Dispatch times out after acceptance: no second dispatch until independently
   reconciled; absence in a recent runs list is not proof of non-acceptance.
5. Token with branch-delete rights removes claim: demonstrate that the
   repository's server-side policy forbids it, otherwise reject Git refs as
   durable claim storage.
6. Different comment ID for same release/main cannot bypass any required
   per-release idempotence rule. Keying only by comment is insufficient if the
   user can submit multiple approved comments on one release; an additional
   release-scoped consumption key or exclusive owner authorization must be
   specified before dispatch.
7. Expired, edited, deleted or author-mismatched issue/comment: deny, even if
   an earlier event payload claimed success.

**Next work item:** inspect actual repository rulesets and effective token
permissions read-only. If their enforced behavior cannot be verified,
choose a backend with demonstrable durable uniqueness instead. Do not grant
new rights as a shortcut.

## B3.2 opslagkeuze na repositorycontrole — 27 oktober 2026

**Voorkeur: een afzonderlijke transactionele opslag met UNIQUE-constraint,
niet een onbeveiligde Git-ref.** Dit is een architectuurbesluit voor de
volgende review en geen toestemming om een database of connector te wijzigen.

Onderbouwing:

- Een Git-ref is alleen geschikt wanneer create-only exclusiviteit,
  bescherming tegen delete/force-update en actorisolatie aantoonbaar zijn.
  De read-only repositorycontrole zag geen rulesets; branch-protection
  uitlezen leverde 403. Die garanties zijn hier dus niet bewezen.
- Een afzonderlijke transactionele database kan een unieke sleutel voor
  **zowel (repo, issue_id, comment_id) als (repo, release_id, release_sha)**
  afdwingen. Daarmee voorkomen we ook het omzeilen van comment-gebaseerde
  replaybescherming door een tweede goedkeuringscomment.
- De bestaande website-MySQL of productietabellen zijn **niet automatisch**
  een geschikte opslag: controllerbeschikbaarheid, rechten, scheiding van
  productiegegevens, netwerktoegang, disaster recovery en auditretentie
  moeten eerst onafhankelijk worden beoordeeld.
- GitHub Issues, labels, workflow-concurrency en artifacts zijn op zichzelf
  geen bewezen, duurzame, atomaire claimopslag.

### Voorstel voor transactioneel contract (uitsluitend specificatie)

1. Verifieer eerst de bestaande B3.1 owner-event-, main-SHA-, manifest-
   en preflightchecks. Een gewone chat-`go` of GitHub-comment zonder
   geverifieerde eigenaar is geen toestemming.
2. Voer één transactie uit met twee onafhankelijk unieke dimensies:
   authorisatie-identiteit en release-identiteit. Schrijf de immutable
   referenties plus de status `CLAIMED`. Commit vóór elke dispatchpoging.
3. Bij conflict, timeout, transactie-onzekerheid of ontbrekend bewijs:
   **geen dispatch**. Alleen een afzonderlijke read-only reconciliatie;
   geen automatische retry op basis van afwezigheid in een zoekresultaat.
4. Leg bewijs vast van wie/wanneer/waarom een claim creëerde, bron-SHA,
   manifestdigest en event-ID. Geheimen en credentials worden nooit gelogd.
5. Een later geslaagde workflow_dispatch-respons is geen deploybewijs;
   observeer het werkelijke Actions-runresultaat en onafhankelijk OVH-readback.
6. Claimrecords niet verwijderen om een mislukte run opnieuw te starten.
   Een nieuw releaseverzoek vereist afzonderlijke expliciete goedkeuring
   en een aantoonbaar nieuwe identiteit.

### Verplichte acceptatiecriteria vóór code met schrijfrechten

- Twee gelijktijdige transacties voor dezelfde release: hoogstens één commit.
- Twee verschillende comments voor dezelfde release: tweede claim wordt
  geweigerd dankzij de release-UNIQUE-constraint.
- Process crash na commit, vóór dispatch: claim blijft bestaan en blokkeert
  herhaling; handmatige herstelprocedure.
- Onzekere database-commit en onzekere workflow_dispatch-uitkomst:
  beide fail-closed, geen tweede dispatchpoging.
- Aantoonbaar least-privilege runtime-identiteit en een controleerbare
  backup-/retentie- en incidentprocedure.
- Het codepad dat claimt mag niet zelf de `production` environment,
  bestaande rollbackworkflow, secrets of releasegate aanpassen.

**Besluitstatus:** opslagkeuze is voorgesteld maar nog **niet operationeel
goedgekeurd**. Geen nieuwe DB, schema, permissie, token, workflow of
network endpoint zonder afzonderlijke securityreview en expliciete opdracht.

## Repository-inventaris van bestaande opslag (read-only, 27 oktober 2026)

De actuele GitHub-`main` op `3734d8e11615d3cf07405c01d7f6b3321b9a9600`
bevat bestaande `database/*_migration.sql` voor **applicatie-/veilinggegevens**
(o.a. rollen, kavels en website-instellingen) en een reeks bestaande
deploy-/controllerworkflows. Er is geen reeds bewezen, afgescheiden
transactionele opslag voor controllerclaims geïdentificeerd op basis van
de repositorystructuur. De aanwezigheid van een OVH-MySQL-database voor de
website is geen rechtvaardiging om releaseautorisaties in die productie-DB
te schrijven.

**Beoordeelde mogelijkheden:**

| Optie | Atomaire dubbele-claimpreventie | Isolatie en beperking |
|---|---|---|
| Bestaande productie-MySQL | UNIQUE constraints technisch mogelijk | Ongewenste koppeling van controller aan live websitegegevens; nieuwe DB-rechten/schema nodig; niet goedgekeurd |
| Afzonderlijke transactionele DB | Twee UNIQUE constraints en transactie uitvoerbaar | Voorkeur voor verdere evaluatie; hosting, credentials, bereikbaarheid, backup en minimumrechten nog onbewezen |
| GitHub create-only refs | Mogelijk atomisch bij create | Delete/force-update- en protection-garanties niet aangetoond; onvoldoende |
| GitHub Issues, labels, artifacts of concurrency | Geen voldoende duurzame exclusieve CAS bewezen | Afwijzen als enige bron van waarheid |
| Lokale SQLite op ephemeral Actions-runner | Lokale transactie mogelijk | Geen gedeelde duurzame opslag over nieuwe runners; afwijzen |

**Go/no-go voor uitvoerende implementatie:** kies uitsluitend een opslag met
aantoonbaar transactionele commit, twee onafhankelijke unieke constraints,
afgeschermde schrijfrechten, gedeelde persistente toegang en crash-/recoverybewijs.
Zolang zo'n infrastructuur niet bestaat of is goedgekeurd, blijft de offline
B3.2-code de veilige eindtoestand. Nieuwe infrastructuur, credentials,
database-schema en dispatch vragen elk aparte expliciete autorisatie.

## B3.2 operationele topologie en afzonderlijke goedkeuringsgates

**Gecontroleerde main na PR #281:** `f69e913ef1b3078e6970eea4cb2fa46d0e50afdd`.
De daar gemergede tijdelijke SQLite-concurrentietest bewijst slechts gedrag
op één geïsoleerde runner. Er bestaat nog geen toegestane gedeelde opslag.

**Voorgestelde scheiding:**

- **GitHub-eventlezer:** alleen read-only toegang tot issue, comment, run,
  manifest en immutable code. Controleert B3.1 en geeft een exact afgebakende
  kandidaatclaim door. Heeft geen database- of OVH-schrijfrechten.
- **Claimservice:** afzonderlijke runtime met duurzame transactionele
  backend. Twee onafhankelijke server-side unieke indices op
  `(repo, issue_id, comment_id)` en `(repo, release_id, release_sha)`.
  Minimaal alleen INSERT en beperkte SELECT/UPDATE op eigen claimtabellen,
  geen DROP/ALTER/DELETE of toegangsbeheer. Beschikbaarheid, continuiteit
  en vertrouwensgrens moeten vooraf bewezen worden.
- **Dispatchservice (nog niet aanwezig):** mag een release uitsluitend na
  *onafhankelijk bevestigde* atomische claim starten, met een aparte
  expliciete productiegoedkeuring. Rechten mogen niet bij de observer
  of de bestaande SFTP-writer worden toegevoegd.
- **Productie-writer:** ongewijzigde bestaande GitHub Actions-releasegate,
  manifestallowlist, remote SHA-driftcontrole, back-up, rollback en
  onafhankelijke browseracceptatie.

**Gating in volgorde:** 1) exacte owner-toestemming en evidence;
2) bron/manifest/main- en scopeverificatie; 3) atomaire claim-commit;
4) afzonderlijke uitvoerende autorisatie; 5) ten hoogste één dispatchpoging;
6) job-/artifact-/serverbewijs; 7) handmatige browseracceptatie.

**Recovery / incidentregels:** na crash of timeout direct na transactie-commit
blijft de claim **verbruikt en geblokkeerd**, zelfs zonder workflowrun.
Een tweede comment op dezelfde release mag niet ontsnappen via nieuw
comment-ID. Toestand `UNKNOWN` is nooit reden voor automatische retry.
Auditgegevens moeten begrijpelijk blijven na herstart, met controle op
retentie, backup/restore, klokverschil, operator-identiteit, incidentproces
en inperking van data/credentials in logs. Nooit claims verwijderen
om een deployment opnieuw te proberen.

**Nog ontbrekend bewijs voor de volgende afzonderlijke goedkeuring:**
werkelijke gekozen hostinglocatie, netwerkbereikbaarheid vanaf een
geautoriseerde runtime, versleuteling/secretbeheer, rol/permissionbewijs,
foutisolatie ten opzichte van de website-MySQL, externe backup- en
herstelproef, onafhankelijke duurzaamheid na runnerherstart, expliciete
database/schema-autorisatie en schriftelijk afgebakende dispatchbevoegdheid.
Geen hiervan is met alleen PR-CI bewezen.

Deze sectie is *alleen architectuur en acceptatiecriteria*; geen nieuwe
database, token, recht, GitHub-eventtrigger, ref, workflow_dispatch,
OVH-write of wijziging aan de releasegate.

## Besluit opdrachtgever: dezelfde bestaande MySQL-database (27 oktober 2026)

**Deze beslissing vervangt de eerdere voorkeur voor een afzonderlijke database.**
De duurzame controllerclaims worden ontworpen in **de bestaande MySQL-database
van 41lokeren.be**, in strikt gescheiden InnoDB-tabellen. Er wordt geen
nieuwe database aangevraagd. De eerdere vergelijkingsparagrafen hierboven
zijn historische afwegingen, niet langer de gekozen architectuur.

**Geplande dataorganisatie (nog geen migratie):**

- Eigen tabel `controller_release_claims`; twee onafhankelijke UNIQUE-sleutels:
  `(repository, issue_id, comment_id)` én
  `(repository, release_id, release_sha)`.
  Verifieer bij implementatie ook of het release-ID over verschillende
  main-SHA's herbruikbaar mag zijn; default is fail-closed bij twijfel.
- Immutable provenance van goedkeuring, releasebron en exacte commit;
  statusovergangen en run-ID alleen gecontroleerd/transactioneel.
- Atomaire InnoDB-insert in één transactie; duplicate key en onbekende
  commituitkomst zijn **geen** toestemming voor dispatch of herpoging.
- App-functies en claimopslag delen fysieke MySQL-host en database, maar
  geen tabelbeheer of herstelrechten. Bestaande websitecode mag de
  claimtabel niet wijzigen of legen.

**Harde nieuwe veiligheidsvoorwaarde bij gedeelde database:
snapshot, herstel en reset.** De bestaande site beschikt over
`Back-up & herstel` en catalogus-reset. Een oude snapshot terugzetten
mag **nooit** een eenmaal geregistreerde autorisatie wissen, overschrijven
of terugrollen. Vóór productiegebruik dus de huidige backup-/restore-
en resetcode volledig auditen: deze tabel moet worden uitgesloten van
data-only snapshot/restore/reset, of een aantoonbaar equivalent
append-only extern auditbewijs/anti-replaymechanisme krijgen. Een
`TRUNCATE`, `DROP`, restore van een oud tabelbeeld of beheerdermatige
DELETE moet detecteerbaar en preventief beveiligd zijn. Alleen
`niet opgenomen in snapshot` is onvoldoende als een beheerder toch
direct de tabel kan aanpassen; documenteer dat resterende risico.

**Rechten en bereikbaarheid:** bekijk eerst de werkelijke OVH
MySQL-accountrechten read-only. Als alleen één DB-gebruiker mogelijk is
en de applicatie dezelfde brede rechten bezit, kan least-privilege
niet worden geclaimd. Vereis aparte minimale DB-credentials voor
claimschrijver waar OVH dat toelaat; geen secrets in GitHub-comments
of CI-logs. GitHub-hosted runners mogen niet stilzwijgend als
rechtstreeks verbonden met OVH-MySQL worden aangenomen.

**Nog afzonderlijk te autoriseren:**
1. Read-only audit van effectieve restore/reset/snapshotdekking en
   MySQL-bereikbaarheid/rechten.
2. Exacte InnoDB-migratie en afzonderlijke productie-DB-goedkeuring,
   inclusief data-only backup en test-herstelstrategie.
3. Aparte minimale credentials en securityreview van runtimepad.
4. Pas daarna afzonderlijke, exacte dispatch-/releasegateautorisatie.

**Huidige toestand:** ontwerpwijziging uitsluitend in documentatie.
Geen SQL uitgevoerd, geen bestaande tabel of backup/restorecode aangepast,
geen nieuwe credential, dispatch of productiedeployment.
