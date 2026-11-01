# Eerste versie: één handmatige poging vanaf macOS

De eigenaar heeft macOS met blijvende lokale opslag gekozen. Deze versie heeft
geen extra server, achtergrondproces, webhookendpoint of automatische herstart.
De Mac draait de controller, OVH blijft de website en de private PHP/MySQL-route
hosten. Het lokale pogingjournal blijft op de Mac, buiten OVH-back-ups.

**Dit is uitvoerbare installatievoorbereiding, geen geactiveerde installatie.**
Een merge installeert geen key/config, SQL, auditrepository of runtime. De
opdrachten hieronder horen bij een later afzonderlijk exact goedgekeurd
bootstrap-/activatieplan. Een configuratiedigest is driftcontrole, geen
eigenaarautorisatie; het exacte verse eigenaarcomment wordt onafhankelijk
gecontroleerd en blijft de productieconsent.

## Benodigd voor één echte poging

- macOS met Python 3.10+ en `/usr/bin/ssh`; geen extra Python-packages.
- Een exacte goedgekeurde checkout op de Mac. Geen runtime-updates tijdens
  een poging. Een private lokale directory met mode `0700`, buiten Git,
  cloud-sync, gedeelde mappen en OVH-restore. Alle bestanden hieronder `0600`,
  eigendom van de lokale gebruiker, geen symlinks of hardlinks.
- Afzonderlijke read-, audit- en dispatchcredentials. De auditcredential heeft
  alleen de afgescheiden privé-auditrepo, expliciet geen admin/maintain. De
  dispatchcredential krijgt alleen benodigde Actions-bevoegdheid; geen
  eigenaarcredential als fallback. Scopes moeten echt bewezen/geprovisioneerd
  worden; verschillende bestandsnamen bewijzen geen verschillende rechten.
- Een aparte beperkte OVH-key die zonder wachtwoordprompt kan werken. Deze
  versie gebruikt geen agent of wachtwoordfallback. Hostkey, keybeperking,
  immutable private code en PHP/PDO-pad moeten vooraf gecontroleerd zijn.
- De eerder beschreven privé-auditrepo met ondersteund privéplan, exacte
  root/ruleset-baseline en één ongebruikte globale poging. Geen automatische
  reset of volgende release. Zie [audit](ovh-controller-independent-audit.md).
- De twee gereviewde claimtabellen op OVH, uitsluitend na aparte exacte SQL-,
  back-up- en rechtenautorisatie. Geen migratiecode in deze runtime.

Credentials blijven in lokale private bestanden. Zet ze niet in chat, command-
arguments, Git of shell-history. Tokenbestanden bevatten één ASCII-token met
hoogstens één afsluitende newline. `claim-hmac.bin` bevat 32–1024 ruwe bytes.
Dezelfde sleutel staat uitsluitend base64-gecodeerd in de private OVH-config.

## Private OVH-bundle

Installatiepad na autorisatie:

```text
<private_root>/<reviewed_revision>/ovh_controller_claim_executor.php
<private_root>/<reviewed_revision>/ovh_controller_claim_store.php
<private_root>/<reviewed_revision>/ovh_controller_claim_runtime.php
<private_root>/config/<reviewed_revision>.json
```

De drie codefiles komen exact uit `scripts/` van de goedgekeurde checkout.
Bereken SHA-256 op de Mac en verifieer ze onafhankelijk na installatie. De
config staat buiten de versiedirectory/webroot, is `0600`, heeft één hardlink
en behoort aan de runtimegebruiker. Symlinks worden geweigerd. De code en
parentdirectories moeten beschermd zijn tegen vervangen tussen hashcheck en
uitvoering. Die hostisolatie wordt niet door CI of een digest bewezen.

De private JSON-config heeft precies de velden `schema` (integer 1), `dsn`,
`user`, `password`, `hmac_base64`. DSN-vorm:
`mysql:host=<dbhost>;port=<port>;dbname=<database>;charset=utf8mb4`.
Geen extra PDO-opties, unix-socket, willekeurige include of credentialloader.
Maak canonical JSON met gesorteerde sleutels, zonder spaties, gevolgd door één
newline, via dezelfde `canonical()`-functie uit `owner_bound_dispatch_ledger`.
Bereken SHA-256 over die exacte bytes zonder de inhoud te loggen. Deze digest
gaat in de lokale configuratie. Publiceer de private config of sleutel nooit.

De runtime accepteert uitsluitend CLI met die exacte digest. Hij verifieert
het private bestand en de digest vóór stdinverwerking. De bestaande HMAC-,
schema-, nonce- en expirychecks lopen vóór de PDO-factory. PHP vereist PDO
MySQL en `posix_geteuid`. Direct zonder argumenten blijft hij `DISABLED`.
De oude executoringang blijft eveneens `DISABLED`.

## Lokale voorbereiding en bediening

Na exacte bootstrap-autorisatie: maak de private directory/bestanden; gebruik
het voorbeeld `deploy/controller/macos-controller.example.json` alleen als
template. Vervang ieder voorbeeldveld met onafhankelijk geverifieerde waarden.
De ingebouwde nul-IDs/ongeldige hostkey maken de template bewust onbruikbaar.
Alle lokale paden zijn absoluut, resolved en privé; ieder credential en het
journal gebruikt een afzonderlijk bestand. De lokale JSON bevat paden/pins,
geen credentialinhoud.

Het aparte journal wordt één keer exclusief gemaakt, zonder netwerk:

```bash
python3 scripts/ovh_controller_local.py init-journal --journal /Users/NAAM/.veiling-controller/attempt.sqlite
```

Deze opdracht overschrijft nooit een bestaand bestand en maakt geen directory
of credentials. Gebruik steeds hetzelfde journal; maak na een onzekere poging
nooit een nieuw journal. Ook `RESERVED` na een lokale crash blijft verbruikt.

Bereken de lokale configuratiedigest met `shasum -a 256` op het private bestand.
Gebruik vanaf de goedgekeurde checkout (vervang pad en digest):

```bash
python3 scripts/ovh_controller_local.py check --config /Users/NAAM/.veiling-controller/controller.json --config-sha256 CONFIG_SHA256
python3 scripts/ovh_controller_local.py review --config /Users/NAAM/.veiling-controller/controller.json --config-sha256 CONFIG_SHA256
```

`check` leest alleen lokale configuratie en bestandsmetadata; geen credential-
inhoud of netwerk. `review` gebruikt uitsluitend het read-token en doet GET-
evidencecontroles; geen auditclaim, SSH, SQL-write of dispatch. Alleen een
exacte eigenaarcomment met nonce en geslaagde onafhankelijke main-push-CI,
manifest/source/remote/preflightbewijs kan de review doorstaan.

Na afzonderlijke exacte activatie- én productieautorisatie:

```bash
python3 scripts/ovh_controller_local.py attempt --config /Users/NAAM/.veiling-controller/controller.json --config-sha256 CONFIG_SHA256
```

Eén proces, één poging; alle bestaande reservering-/recheck-/attempt-gates
blijven intact. Stop bij iedere fout of onderbreking. Geen herhaling, nieuw
journal, gewijzigde nonce, workflow-rerun of reset als herstelactie.
`ACCEPTED_RECONCILIATION_REQUIRED` betekent alleen dat de POST is aanvaard;
de parentclaim blijft `DISPATCH_UNKNOWN`, nooit bewezen productieacceptatie.

Alleen onafhankelijk read-only reconstrueren:

```bash
python3 scripts/ovh_controller_local.py reconcile --config /Users/NAAM/.veiling-controller/controller.json --config-sha256 CONFIG_SHA256 --nonce EXACTE_NONCE
```

Dit leest audit/SQL/run-kandidaten en laadt geen dispatchcredential. Het geeft
geen retryrecht of bevestigde run-identiteit. Deploymentbeveiliging met exacte
remote hashes/drift, back-up/rollback, onafhankelijke readback en functionele
browseracceptatie blijft verplicht. De Mac mag na de afgeronde CLI stoppen;
een reeds gestarte Actions-run wordt daardoor niet teruggedraaid.

## Bewijs vóór activatie

CI test de echte CLI-config-/credentialloader en de echte private PHP-runtime
tegen tijdelijke lokale bestanden en geïsoleerde MySQL. GitHub/SSH/HTTP blijven
expliciete testdubbels. Dit bewijst geen echte Mac-fsync, OVH-hostkey, server-
keybeperking, privéplan/rechten of geïnstalleerde schema/config. Die beperkte
live bootstrap en inspectie vormen de volgende concrete autorisatiegrens.
