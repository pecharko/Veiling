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
