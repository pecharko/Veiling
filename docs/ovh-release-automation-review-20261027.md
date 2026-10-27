# Minder handmatige interacties bij OVH-releases — actuele ontwerpbeslissing

Status: **ontwerp ter review op PR #245; geen geactiveerde nieuwe deployroute**.
Bijgewerkt: 27 oktober 2026, gecontroleerd tegenover de repository op `main`
(commit `1233f2a1ce4393294eaa3f5443fe7adedd218da3`).
**Deze PR bevat uitsluitend dit document.** Het ontwerp is geen merge- of productieautorisatie.

## Wat is gerealiseerd en wat niet?

| Onderdeel | Feitelijk gerealiseerd | Resterende grens |
| --- | --- | --- |
| A. Read-only manifestvoorbereiding | `shared-ovh-auto-manifest.yml` berekent bron-/remote-hashes; PR #246 voegt retry en behoud van manifestbranch toe | GitHub Actions kan ondanks `pull-requests: write` soms geen manifest-PR openen. De connector kan de branch terugvinden en de PR aanmaken; automatische GitHub-PR-aanmaak is niet end-to-end bewezen |
| B. Machineleesbaar deploymentbewijs | PR #247 contract; #248 writer en evidence-artifact; #249 onafhankelijke vergelijking; #250 GitHub API discovery en veilige artifactredirect; #251 workflow_run read-only verificatie; #252 correct workflowpad `@main` | Alle regressietests waren groen, maar een **echte** productierun na deze wijzigingen is nog niet end-to-end beoordeeld |
| C. Eenmalig geautoriseerde dispatch | Gedeelde `shared-ovh-release.yml` vereist `workflow_dispatch`, `release_id` en `confirmation=DEPLOY-<id>-<current-main-SHA>` met pinned SFTP, driftgate, backup, rollback en readback | ChatGPT-GitHub-connector biedt geen gedocumenteerde `workflow_dispatch`. De **bestaande** `verified-deploy-controller.yml` kent issue-gestuurde eenmalige dispatch voor geregistreerde oudere workflows, maar ondersteunt het gedeelde invoercontract niet |
| D. Afsluiting/acceptatie | De read-only verifier publiceert een technisch resultaat en laat browseracceptatie `PENDING` | Browseracceptatie en afsluiting van bestaand issue/manifest-PR zijn nog **geen** automatisch bewezen end-to-end proces |

De OVH Application Firewall kan GitHub-hosted HTTPS probes met HTTP 403 blokkeren.
Dat is geen succes van een rooktest, maar evenmin zelfstandig bewijs dat de publieke website offline is.
De bestaande productie-writer en beveiligingscontroles blijven leidend.

## Eerstvolgende bouwstap: geen tweede controller

**Voorkeursontwerp: een strikt beperkte adapter voor de bestaande, gereviewde
`verified-deploy-controller.yml` met uitsluitend de gedeelde manifestgestuurde
OVH-release als extra doel.**

De huidige controller gebruikt een `issue_comment`-trigger met
`contents: read`, `issues: write` en `actions: write`. Hij vereist een
door de repository-eigenaar aangemaakt, specifiek gelabeld `DEPLOY-AUTH:`-issue
én een comment van die eigenaar; hij controleert of het issue niet eerder is
gesloten en sluit het vóór eenmalige dispatch. Hij stuurt momenteel andere velden
(`mode=deploy`, `release_sha` en bestandsgebonden hashes) naar
geregistreerde legacy-workflows. **Die payload is niet compatibel** met
`shared-ovh-release.yml` en mag nooit rechtstreeks worden hergebruikt.

### Nieuwe adapter: voorgestelde eisen

1. **Geen impliciete autorisatie uit een chatbericht of repository-write.** Een
   final approval in ChatGPT volstaat niet als cryptografisch/geauthenticeerd
   GitHub-owner-event. De owner-gecontroleerde GitHub-actie blijft nodig zolang
   de connector geen aantoonbaar veilige gedelegeerde dispatch biedt. Maak geen
   nieuwe `issue_comment`-workflow, geen extra token en geen auto-deploy op merge.
2. **Exacte scope.** Accepteer een nieuwe, versiegebonden approvalgrammatica voor
   uitsluitend `shared-ovh-release.yml`: bestaande `release_id`,
   immutable actuele `main` SHA, SHA-256 van het volledige manifest, exacte
   file-allowlist en alle remote SHA-256- of `ABSENT`-baselines. Autorisatie
   vermeldt rollback en resterende downtime-/herstelrisico's; geen wildcard.
3. **Herleidbare read-only baseline.** Controleer de daadwerkelijke
   auto-manifest-run, status, commit, event, immutable artifactinhoud en
   manifestbranch/PR-provenance. Houd rekening met het feit dat de
   auto-manifest-run op de `main`-SHA **vóór manifestmerge** kan staan;
   de uiteindelijk goedgekeurde deploy-SHA moet juist de **nieuwe**
   `main`-SHA na manifestmerge zijn. Controleer daarom dat de bron- en
   remote-hashes onderling overeenkomen zonder die twee SHA's gelijk te
   veronderstellen. Geen fictieve remote baseline of stille heropname.
4. **One-shot en racebestendig.** Valideer owner van issue en comment, exact
   label/titel, open status, nooit eerder gesloten, geldigheidsduur,
   eenmalig consumeermechanisme en concurrency. Vóór dispatch opnieuw
   `main`, manifest, preflightstatus en approval controleren. Bij een
   dispatchfout na consumptie: **nooit** hetzelfde issue heropenen/hergebruiken.
5. **Exact bestaand workflowcontract.** Bouw uitsluitend
   `gh workflow run shared-ovh-release.yml --ref main -f release_id=<id>
   -f confirmation=DEPLOY-<id>-<main SHA>`.
   Geen `mode`, geen `release_sha`, geen hergebruik van legacy-hashinputs;
   geen wijziging in bestaande writer, production environment of
   SSH-hostkey-/drift-/rollbackgates.
6. **Audittrail.** Registreer in het bestaande goedkeuringsissue het verband
   tussen immutable SHA, manifest, baselines, controller-run, gedeelde
   dispatch-run en uiteindelijk evidence-/rollbackartifact (zonder secrets).
   Een succesvolle API-dispatch is **niet** gelijk aan een succesvolle
   productie-uitrol.
7. **Technische en menselijke acceptatie gescheiden.** De read-only
   verificatie moet exact deze releaserun bewijzen en nog steeds
   `browser_acceptance=PENDING` rapporteren. Na de functionele
   browseracceptatie één registratie in bestaand manifest-PR/issue.
   Geen extra PR's voor administratie.

### Wat moet vóór implementatie beslist/bewezen worden?

- Of de GitHub-connector daadwerkelijk namens de repository-eigenaar een
  approval-issue en comment kan plaatsen; de huidige code eist letterlijk
  `github.repository_owner` als beide auteurs. Een bot-/app-comment
  **faalt terecht** en mag niet worden omzeild via identiteitsvervalsing.
- Of GitHub Actions in deze repository de beperkte dispatch via de bestaande
  controller kan uitvoeren en protected-environment reviewers correct
  afdwingt. Geen aanname van beschikbaarheid enkel op basis van YAML.
- Of de laatste manifest-artifact en git-referenties zonder dubbele
  preflight op de exacte release kunnen worden gekoppeld.
- Wat de gebruiker nog zelf doet wanneer geen owner-authenticatie vanuit
  deze connector mogelijk is: de bestaande veilige handmatige dispatch met
  twee velden, of een expliciete owner-actie in GitHub.
- Het read-only rapport moet aangeven **waarom** de autorisatie of bewijscontrole
  faalde, zonder secrets, persoonsdata of tokenwaarden te loggen.

## Fasen en afzonderlijke autorisatiegrenzen

1. **Nu: PR #245 als documentreview.** Beoordeel deze feitelijke status,
   controlleradapter en veiligheidsvoorwaarden; CI mag op de document-PR
   draaien. **Geen automatische merge** van deze ontwerp-PR.
2. **Nieuwe geïsoleerde adapter-PR, zonder productiegebruik.** Bouw alleen
   testbare pure validatie, parser en exacte commandoconstructie; voer
   regressietests met gemockte GitHub-responses uit. Minimaal: ontbrekend
   artifact, gemanipuleerd manifest, verkeerde auteur, herhaald issue,
   aflopende approval, onjuiste main SHA, verkeerde baselines, race en
   mislukte dispatch. Geen productie-API-calls tijdens tests.
3. **Afzonderlijke securityreview en expliciete exacte mergegoedkeuring**
   vóór **iedere** aanpassing aan bestaande controller, workflow,
   actieschrijfrechten, triggers, registry of releasegate.
   Nieuwe `actions: write`-rechten of ruimere tokenrechten zijn
   **niet** standaard toegestaan.
4. **Niet-productieve dry-run.** Bewijs dat de adapter de juiste dispatchpayload
   maakt en bij alle negatieve gevallen fail-closed stopt, zonder OVH-upload.
   De productieomgeving blijft onaangeroerd.
5. **Eerste live gebruik uitsluitend na nieuwe concrete releaseautorisatie**
   met exacte `main` SHA, bestandspaden, geverifieerde baselines,
   driftcontrole, backup, 7 dagen rollback en expliciete aanvaarding
   van het risico op downtime/gedeeltelijk herstel. Geen productie-run als
   vrijblijvende proef.
6. **Onafhankelijke post-releasecontrole** op echte jobs, readback hashes
   en rollbackartifact; handmatige browseracceptatie blijft afzonderlijk.
   Ontbrekend bewijs = niet bewezen, nooit automatisch `groen`.

## Beslispunt bij afronding van PR #245

Een beveiligde, **door de repository-eigenaar geauthenticeerde eenmalige
actie** is de enige passende volgende dispatchroute zonder verandering van
de bestaande beveiligingsgrenzen. De huidige geautoriseerde
`workflow_dispatch` met twee invoervelden blijft de fallback.
`PR #245` zelf voert geen enkele write op OVH, database, cron of
productie-deploy uit en geeft geen toestemming om later automatisch te deployen.
