# Versie 1: handmatig vanaf je Mac

De eigenaar koos de eigen Mac met blijvende lokale opslag. Daarom kan de eerste
versie rechtstreeks de bestaande `shared-ovh-release.yml` starten en het
bestaande duurzame SQLite-ledger lokaal gebruiken. De Mac is een afzonderlijke
opslagautoriteit buiten OVH: een OVH-site-/database-restore zet dit ledger niet
terug. Er is voor deze variant geen extra server, privé-auditrepository,
OVH-claimdatabase, SSH-claimtransport of PHP-bootstrap nodig. Die bestaande
voorstellen blijven inactief; ze zijn geen fallback voor dit commando.

Een merge installeert of activeert niets. Lokale inrichting/credentials en de
exacte echte dispatch vereisen afzonderlijke eigenaarautorisatie. Een gepinde
configuratie is driftcontrole, geen autorisatie. De productieconsent blijft
een exact vers GitHub-eigenaarcomment dat onafhankelijk wordt gecontroleerd.

## Minimale inrichting na afzonderlijke toestemming

1. Python 3.10+ op macOS en een checkout op de exact goedgekeurde commit.
   Er zijn geen extra Python-packages of achtergrondservice nodig.
2. Een private directory buiten de checkout, cloud-sync en gedeelde mappen,
   bijvoorbeeld `/Users/NAAM/.veiling-controller`, mode `0700`.
3. Twee aparte credentialbestanden, `read-token` en `dispatch-token`, mode `0600`,
   eigendom van de lokale gebruiker, geen symlinks of hardlinks. Het eerste
   credential is read-only; het tweede krijgt alleen de benodigde Actions-
   dispatchbevoegdheid voor `pecharko/Veiling`. Scopes moeten echt gecontroleerd
   worden; aparte bestanden bewijzen geen aparte rechten. Geen brede
   eigenaarcredential als automatische fallback. Er wordt niets aangemaakt,
   gelezen of gemint door de ontwikkel-/CI-taak.
4. Een bestaande goedgekeurde release met manifest, authentieke remote baseline/
   read-only preflight en geslaagde onafhankelijke main-push-CI. Het verse
   eigenaarcomment bevat exacte main/manifest/files/nonce en is van de numeriek
   geverifieerde eigenaar. Bestaande bewijschecks blijven verplicht.

Zet credentials nooit in chat, Git, command-arguments of shell-history. Een
tokenbestand bevat alleen het ASCII-token, eventueel met één afsluitende newline.
Gebruik voor invoer een veilige editor/credentialvoorziening; geen `echo TOKEN`.
De controller zoekt geen credentials in environmentvariabelen.

Maak het aparte lokale ledger één keer expliciet, zonder netwerk:

```bash
python3 scripts/ovh_controller_local.py init-journal --journal /Users/NAAM/.veiling-controller/attempt.sqlite
```

Dit maakt geen directory of credentials en overschrijft nooit een bestaand
bestand. Maak geen nieuw journal na een fout. Gebruik geen journal uit een
andere controller of ephemeral Actions-runner. De gewone aanroep zonder modus
blijft `DISABLED` en leest geen configuratie of credentials.

Kopieer `deploy/controller/macos-controller.example.json` naar de private
directory als `controller.json`, mode `0600`. Vul de exacte issue/comment-IDs
en de drie afzonderlijke absolute bestandspaden in. De nul-IDs in de template
worden geweigerd. Geen secrets of willekeurige Python-/commandconfig in JSON.

Bereken SHA-256 met `shasum -a 256` op het private configuratiebestand. Gebruik
die digest als `CONFIG_SHA256` in onderstaande commando's; dit voorkomt
stilzwijgende configuratiedrift na review.

## Controle, één poging en reconciliatie

```bash
python3 scripts/ovh_controller_local.py check --config /Users/NAAM/.veiling-controller/controller.json --config-sha256 CONFIG_SHA256
python3 scripts/ovh_controller_local.py review --config /Users/NAAM/.veiling-controller/controller.json --config-sha256 CONFIG_SHA256
```

`check` leest alleen lokale configuratie en bestandsmetadata: geen credential-
inhoud of netwerk. `review` leest uitsluitend het read-token en GET-evidence;
geen journalwrite of workflowdispatch. Het resultaat toont alleen de
bindingdigest en status, geen nonce, comment, token of ruwe fout.

Na afzonderlijke exacte activatie- en productieautorisatie:

```bash
python3 scripts/ovh_controller_local.py attempt --config /Users/NAAM/.veiling-controller/controller.json --config-sha256 CONFIG_SHA256
```

De bestaande `OvhEvidence`, `Ledger`, `_attempt_once` en `GitHubDispatch` lopen
direct achter elkaar. Verifieer eigenaar/release/main/manifest/source/remote/
preflight en drie onafhankelijke push-CI-runs; reserveer atomair nonce, consent,
release en actief slot; controleer al het bewijs opnieuw; commit ATTEMPTED
vóór één vaste POST. Een crash/fout houdt de reservering vast. Reopenen van
hetzelfde ledger staat dezelfde autorisatie nooit opnieuw toe.

`ACCEPTED_RECONCILIATION_REQUIRED` is alleen een POST-ack, geen bevestigde run of
productieacceptatie. Stop bij iedere fout/onderbreking. Geen automatische retry,
nieuw journal, handmatig wissen, noncewissel of workflow-rerun als herstel.
Lees uitsluitend onafhankelijk terug:

```bash
python3 scripts/ovh_controller_local.py reconcile --config /Users/NAAM/.veiling-controller/controller.json --config-sha256 CONFIG_SHA256 --nonce EXACTE_NONCE
```

Dit leest het bestaande lokale claimrecord en GitHub-run-kandidaten. Het leest
geen dispatch-token en doet geen POST. Een kandidaat verleent geen retryrecht,
bevestigde dispatchidentiteit, onafhankelijk OVH-bewijs of browseracceptatie.
Na de CLI kan de Mac stoppen; een reeds gestarte Actions-run gaat verder.

## Opslaggrens en productiechecks

Het ledger gebruikt SQLite `synchronous=FULL` en lokale atomische transacties.
CI test ook op macOS. Werkelijke opslagduurzaamheid/toegang op de eigen Mac
moet bij bootstrap worden gecontroleerd. **Herstel dit journal niet naar een
oudere Mac-/Time Machine-back-up en vervang of wis het niet.** Dit ontwerp
beschermt tegen OVH-restore, niet tegen het terugzetten van de Mac-autoriteit
zelf. Na Mac-verlies/restore of onzekere opslag: STOP, geen nieuwe dispatch;
onafhankelijke reconciliatie en een apart exact beoordeeld herstelbesluit.
Wie ook automatische bescherming tegen Mac-rollback wil, heeft de eerder
voorgestelde externe auditautoriteit en aparte provisioning nodig.

De bestaande productie-workflow blijft de enige deployer. Pinned SFTP-hostkey,
verse per-file remote driftcontrole, bronhashes, allowlist, back-up/rollback,
readback en functionele browseracceptatie blijven vereist. Er worden geen
workflowpermissions, productieomgevinggates, secrets of OVH-rechten gewijzigd.
Geen daemon, scheduler, automatische vrijgave of retry. Uitbreiding naar
onbeheerde/redundante uitvoering is geen onderdeel van versie 1.

## Opeenvolgende releases: expliciete afronding (journalversie 2)

De Mac blijft de gecontroleerde opslagautoriteit buiten OVH-webhosting. Gebruik
altijd hetzelfde journal. Deze uitbreiding activeert niets en wijzigt geen
credentials, GitHub-rechten, productie-SQL of deploymentworkflow. Upgrade en
lokale afronding worden pas uitgevoerd na afzonderlijk toepasselijke toestemming.

Een bestaande versie-1-database wordt nooit automatisch gemigreerd. Met de
nieuwe, exact beoordeelde checkout en afzonderlijke lokale autorisatie:

```bash
python3 scripts/ovh_controller_local.py upgrade-journal --journal /Users/NAAM/.veiling-controller/attempt.sqlite
```

Dit controleert de oorspronkelijke schema/index, bewaart onder een schrijfslot
een exclusief aangemaakte private `attempt.sqlite.v1-backup.sqlite`, migreert
atomair en vergelijkt alle oorspronkelijke claimvelden. De oude ACCEPTED/
UNKNOWN/ATTEMPTED/reserveringsstatus blijft staan. Een bestaand backupbestand,
onbekend schema of fout stopt zonder vrijgave. De backup is bewijs; herstel die
niet over het huidige journal. Initialiseer geen tweede journal als omweg.

Alleen een ACCEPTED claim kan vervolgens COMPLETED worden. UNKNOWN, ATTEMPTED en
RESERVED blijven geblokkeerd. Eerst controleert de eigenaar de onafhankelijke
readback en browserwerking en plaatst persoonlijk een **nieuw, onbewerkt**
comment op het oorspronkelijke eigenaarissue, met exact onderstaande tekst en
de werkelijke oude waarden (zonder codeblok):

```text
ACCEPT MAC RELEASE WITH READBACK
nonce=OUDE_NONCE
release_id=OUDE_RELEASE_ID
release_sha=OUDE_MAIN_SHA
run_id=WERKELIJKE_RUN_ID
acceptance=READBACK-AND-BROWSER-ACCEPTED
```

Dit comment moet na de succesvolle run zijn geplaatst en hoogstens 24 uur oud
zijn. Het is een persoonlijke attestatie van readback/browseracceptatie; de
controller voert zelf geen onafhankelijke SFTP/browsercontrole uit. Een publieke
HTTPS-hash bewijst publieke bereikbaarheid/bytes, geen geauthenticeerde directe
OVH-originreadback. De eigenaar moet die beperking bij acceptatie beoordelen.
Een agent plaatst dit comment niet namens de eigenaar.

Na afzonderlijke lokale toestemming:

```bash
python3 scripts/ovh_controller_local.py complete --config /Users/NAAM/.veiling-controller/controller.json --config-sha256 CONFIG_SHA256 --nonce OUDE_NONCE --run-id WERKELIJKE_RUN_ID --acceptance-comment-id NIEUW_PERSOONLIJK_COMMENT_ID
```

Dit leest uitsluitend het read-token. Onafhankelijke vaste GitHub-GETs controleren
het oorspronkelijke ownercomment, het verse acceptatiecomment, één unieke
succesvolle eerste workflowpoging op de oude immutable main, het oude manifest,
alle readback-hashes en beide onexpired evidence-/rollback-artifacts. ZIP-digests,
backupmetadata en alle vorige bestandsbytes moeten overeenkomen. Pas daarna
wordt atomair een receipt opgeslagen en de claim COMPLETED. Er volgt geen POST.
Geen claim of nonce wordt verwijderd: nonce, issue/comment en release-ID blijven
voor altijd uniek/verbruikt. Run-correlatie bewijst geen cryptografische echo van
de oorspronkelijke POST; de oorspronkelijke reconciliatiebeperkingen blijven
bestaan. Een mislukte of onzekere afronding wordt uitsluitend read-only onderzocht.

Een volgende release vereist een nieuw exact goedgekeurd manifest, immutable
main met onafhankelijke push-CI, nieuw persoonlijk eigenaarcomment en nieuwe
nonce/release-ID. Wijzig pas dan de private configuratie en controleer haar nieuwe
SHA-256. De bestaande `review` en één `attempt` gelden opnieuw; afronding is geen
volgende productieautorisatie en start nooit automatisch een release.

Voor één feature-PR accepteert de evidencelezer nu ook een succesvolle eerste
`shared-ovh-readonly-preflight.yml`-run op main. Het authenticieke artifact bevat
alleen `shared-ovh-readonly-baselines.json`. De oorspronkelijke bronhashes worden
gecontroleerd tegen onafhankelijk opgehaalde bestanden op de preflight-SHA; de
nieuwe bronhashes tegen de uiteindelijke main. Padvolgorde en remote baselines
moeten exact gelijk zijn, de ZIP-digest moet kloppen. Er is geen release-request
of tweede manifest-PR nodig. De oude auto-manifestroute blijft ongewijzigd strikt;
alleen daar bestaat het eerder beoordeelde herstel voor geweigerde bot-PR-aanmaak.
