# Onafhankelijke auditautoriteit — dormant GitHub-voorstel

Dit voorstel vult de ontbrekende providerinterface uit #518 in. De code kiest
geen live backend en maakt geen repository, branch, ruleset, credential of
claim aan. `execute()` blijft onvoorwaardelijk `DISABLED`. Geen bestaande
workflow, dispatcher of credentialloader instantieert `GitHubAudit` automatisch.

## Architectuur en scope

Voorgestelde afzonderlijke opslag: **privé** `pecharko/Veiling-release-audit`.
Deze naam is een voorstel, geen claim dat het repository bestaat of beschikbaar
is. Hiermee staat claimhistorie buiten OVH-bestanden en databaseback-ups, zonder
een extra server. Auditcredentials mogen geen toegang tot productierepository,
Actions-dispatch, OVH, database of beheer van auditbeleid hebben.

Accountvoorwaarden moeten onafhankelijk worden vastgesteld. GitHub ondersteunt
rulesets op privé-repositories bij Pro/Team/Enterprise Cloud, niet algemeen bij
Free. Er is geen planniveau, aanschaf of upgrade vastgesteld of uitgevoerd. Een
openbaar auditrepository is geen automatische privacyfallback. Zie
[GitHub: rulesets](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets).

## Eén atomaire nieuwe reservering

De voorgestelde writer doet één vaste `createCommitOnBranch`-mutatie met een
exacte `expectedHeadOid`, één toevoeging `claims.json` en de volledige gevalideerde
releasebinding. GitHub beschrijft dat deze mutatie commit en branch samen bijwerkt
en de vooraf verwachte head als verplicht inputveld gebruikt. De branch wordt
expliciet gebonden aan auditrepo/main. Er zijn geen losse blob/tree/ref-writes,
force-update, willekeurige GraphQL-query, deletion of retry-loop. Zie
[mutatie en expectedHeadOid](https://docs.github.com/en/graphql/reference/commits#createcommitonbranch)
en [CommittableBranch](https://docs.github.com/en/graphql/reference/git#committablebranch).

Volgorde in `GitHubAudit.reserve_once(binding)`:

1. Controleer de exacte bestaande owner/main/manifest/file/CI-binding uit #518.
2. Lees zelfstandig auditrepo-identiteit en effectieve repo-rolemetadata. De
   numerieke repository-ID moet bij de goedgekeurde baseline horen; zelfde naam
   met nieuw ID na verwijderen/heraanmaken wordt geweigerd. Alleen private,
   niet-gearchiveerde, niet-forked repository met main wordt aanvaard.
3. Eis pull/push, expliciet geen admin/maintain en dezelfde numerieke eigenaar.
   Ontbrekende of onvoldoende rolemetadata blokkeert. Deze metadata bewijst niet
   zelfstandig alle scopes van een token/App: aparte uitgifte, repositoryselectie
   en onvermogen tot policybeheer blijven een externe activatievoorwaarde.
   Gebruik geen bredere eigenaarcredential om een weigering te omzeilen.
4. Lees de vastgepinde ruleset-ID en versie (`updated_at`). Vereist: actief,
   exact refs/heads/main, geen uitzonderingen/bypassactors en precies deletion,
   non_fast_forward en required_linear_history. Iedere policywijziging, ook
   tijdelijk wijzigen en terugzetten met een gewijzigde timestamp, maakt de
   gepinde versie ongeldig. Timestamp-readback bewijst niet de volledige
   beleidshistorie of wijzigingen binnen dezelfde timestampresolutie.
5. Verifieer de gepinde **rootcommit** met leeg canoniek bootstrapledger, en
   huidige main. Git-trees moeten uitsluitend één normaal `100644`-blob voor
   claims.json bevatten. Bloblengte, base64, Git-blob-OID en canonieke JSON worden gecontroleerd;
   ontbrekend, symlink, onvolledig of corrupt bewijs betekent geen vrije nonce.
6. Alleen de lege bootstrap is beschikbaar. Controleer policy opnieuw en doe
   eerst de hieronder beschreven duurzame lokale pogingregistratie. Controleer
   policy daarna opnieuw en doe één CAS-mutatie met de waargenomen immutable head.
7. Na de response: controleer policy opnieuw en lees het resultaat onafhankelijk
   via REST. Alleen exact de geacknowledgde commit met parent=bootstrap en één
   exacte binding levert een **nieuwe** typed `AuditReservation` op.

Een gelijktijdige stale-head fout, GraphQL-error, timeout, partial response of
afwijkende readback geeft geen receipt. Na een mogelijk begonnen mutation stopt
de writer in die instance permanent. `lookup(nonce)` blijft alleen read-only en
kan nooit een nieuwe receipt of retryrecht produceren. Een gecommitteerde claim
blokkeert ook een nieuwe process/instance na herstart of OVH-restore.

Een ontbrekende claim na een onzekere POST bewijst geen niet-uitvoering. De
duurzame lokale pogingregistratie hieronder blokkeert dezelfde runtime ook bij
herstart/redelivery en lege externe readback. De in-memory onzekerheidsflag is
alleen een extra blokkade. Dit voorstel activeert geen runtime of restartbeleid.

## Duurzaam STOP over procesherstart

`GitHubAudit(api, config, attempt_journal)` vereist voor iedere mutatie een
apart, vooraf geprovisioneerd `owner_bound_dispatch_ledger.Ledger`. Zonder
journal is uitsluitend `lookup` mogelijk; `reserve_once` weigert vóór API-I/O.
Er is geen runtime-defaultpad, automatische provisioning of fake fallback.

Na de read-only preflight, vóór de eerste externe schrijfactie:

1. `Ledger.reserve` schrijft atomair de volledige releasebinding en auditrepo-ID,
   root-SHA, ruleset-ID en policytimestamp. Het bestaande globale unieke slot
   staat hoogstens één poging toe, ook tussen onafhankelijke processen.
2. Een tweede duurzame transactie zet `RESERVED` naar `ATTEMPTED`; exacte lokale
   readback is verplicht vóór de GitHub-mutatie. Het bestaande ledger gebruikt
   SQLite `synchronous=FULL`. Een lokale fout of ontbrekend/corrupt journal
   levert geen externe schrijfactie op.
3. Er is geen overgang naar `ABORTED`, reset, vrijgave of herstel van authority.
   Ook een succesvolle auditreserve houdt het pogingjournal verbruikt. Een
   crash tussen de twee lokale transacties blokkeert conservatief via RESERVED;
   een crash tijdens/na de externe aanroep via ATTEMPTED.

Na een procesherstart opent de runtime exact hetzelfde journal in bestaande
`mode=rw`; ook een verse provider met een leeg fake/externe bootstrap kan geen
tweede poging reserveren. Een nieuwe nonce/consent/release heft de globale
blokkade evenmin op. Lookup leest alleen extern bewijs en geeft nooit een receipt.

**Opslagvoorwaarde:** dit is een single-host journal op een vooraf goedgekeurd
privé-filesystem met aantoonbare duurzame SQLite/fsync-semantiek. Het moet de
runtimeherstart overleven en buiten OVH-site-/database-restore en ephemeral
Actions-runners blijven. Geen netwerkfilesystem, jobartifact of nieuw leeg
journal per start. Het journal is een aparte database, niet het SQL-claimstore
of een hergebruikte dispatchledger. De runtime moet pad, identiteit en toegang
beschermen: deze adapter bewijst geen bescherming tegen vervanging, wissen,
rollback van het journal of een beheerder die alle opslag herschrijft.

Er is geen geschikte runtime/opslag aangemaakt of bewezen. Als alleen OVH of
een ephemeral runner beschikbaar is zonder onafhankelijke duurzame opslag,
blijft activatie geblokkeerd. Dit voorstel provisioniert geen extra server.
De externe GitHub-claim blijft nodig voor replaybescherming na OVH-restore;
het lokale journal vervangt die onafhankelijke autoriteit niet.

## Conservatieve globale blokkade

De geaccepteerde historie is uitsluitend de bootstrap en hoogstens één directe
opvolger met één claim. Daardoor sluiten nonce, consent, release en globale
actieve dispatch elkaar uit. Een opvolgende commit die het ledger leegt, extra
historie, ontbrekende data of een onverklaarde head wordt geweigerd; de provider
valt nooit terug naar een oudere groene snapshot.

Dit is bewust **één globale dispatchpoging**, geen automatisch vrijgegeven
releasesysteem. Er is geen reset, unlock, delete of ankerrotatie-interface. Zet
een later root/anker of andere config niet stilzwijgend in om een verbruikte
autorisatie te hergebruiken. Een toekomstige gecontroleerde volgende release
vereist een afzonderlijk beoordeeld lifecycle-/reconciliatieontwerp.

## Voorbereide bestanden, niets toegepast

- `deploy/controller/audit-claims.bootstrap.json`: exact leeg datafile voor een
  later afzonderlijk geautoriseerde rootcommit. Niet geïnstalleerd of gecommit
  in een auditrepository.
- `deploy/controller/audit-main-ruleset.proposal.json`: voorstel uitsluitend voor
  het auditrepository; niet toegepast op auditrepo of `pecharko/Veiling`.
- `AuditConfig`: vereist echte goedgekeurde repo-ID, root-SHA, ruleset-ID en
  policytimestamp. Geen productie-defaults of automatisch verzamelde credentials.

De private auditrepo, relevante planondersteuning, reader/writer-identiteit,
scopes, policy en bootstrap moeten eerst afzonderlijk exact worden goedgekeurd
en geprovisioneerd. De bestaande GitHub-connector/eigenaartoken wordt hiervoor
niet hergebruikt; geen writer/App-token wordt aangemaakt of gelezen. Verifieer
ook de GitHub-API-rolemetadata daadwerkelijk met de gekozen credential. Als die
niet beschikbaar is, blijft de gate dicht; er is geen booleaan-bypass.

## Bewijs en activatie

Lokale tests gebruiken een strikt afgescheiden fake GitHub met atomaire CAS.
Zij testen clientgedrag: parallelle contenders, gewijzigd repo-ID/owner/role,
policy drift/bypass/scope, symlink/blob/historiecorruptie, fout na commit, lookup
zonder authority en de echte controllerseam met gesimuleerde SQL-restore.
Extra subprocess-tests beëindigen een proces na de lokale ATTEMPTED-commit en
tijdens een fake POST. Een nieuw proces met lege externe fake blijft geblokkeerd.
Twee onafhankelijke processen delen hetzelfde echte tijdelijke SQLite-journal
en kunnen samen hoogstens één fake externe aanroep doen. Lokale commit-ackfouten,
afwijkende readback en een ontbrekend/corrupt journal blokkeren eveneens.
De fake is geen bewijs van echte GitHub-duurzaamheid, privéplanondersteuning of
werkelijke repositoryrechten. De documentatie verifieert het API-contract;
een live harmless CAS-/permissions-/herstelproef vereist eigen exacte toestemming.

Public execute en de bestaande controller-, SSH- en PHP-ingangen blijven uit.
Voor activatie blijven nodig: echte externe auditprovisioning met onafhankelijk
bewijs, geconfigureerde beperkte credentials, runtime STOP/NO RETRY bij herstart,
inclusief onafhankelijk bewezen persistentie en isolatie van het pogingjournal,
geverifieerde private OVH-SSH-/code-/keyisolatie en apart exact DB-/bootstrap-/
activatiebesluit. Geen merge van deze code schakelt die voorzieningen in.
