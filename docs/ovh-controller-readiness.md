# Releasecontroller: volledige ketentest en activatiegrens

Deze controle bouwt voort op de gemergede, inactieve onderdelen #516–#521.
De eigenaar koos daarna een handmatig gestarte controller op de eigen Mac.
De uitvoerbare voorbereiding staat in [macOS versie 1](ovh-controller-macos-v1.md).
Die handmatige variant gebruikt het bestaande lokale SQLite-ledger rechtstreeks
buiten OVH, met de bestaande releaseworkflow. De onderstaande audit-/SQL-/SSH-
proposals zijn voor de eerdere OVH-claimstorevariant, geen verplichte extra
installaties voor macOS versie 1. Bescherming tegen terugzetten van de Mac zelf
is een aparte grens; na Mac-restore stopt deze variant zonder nieuwe poging.
De bestaande OVH-webhosting blijft de websitehost; SSH-beschikbaarheid is door
de eigenaar gemeld. Er is geen extra server, runtime, auditrepository, key,
journal of endpoint aangemaakt. De publieke Python/WSGI/PHP-ingangen blijven
`DISABLED`. Deze testwijziging voegt geen nieuwe productie-ingang toe.

## Eén geteste samenhangende keten

`tests/ovh_controller_fullchain_test.py` verbindt de bestaande componenten:

1. `authenticate` verifieert de ruwe HMAC-ondertekende GitHub-commentenvelop.
2. `BoundReader(OvhEvidence(...))` bindt iedere onafhankelijke evidencecheck
   opnieuw aan dezelfde eigenaar, issue, comment en approvaldigest. De echte
   validators lezen testfixtures voor immutable main/manifest, filehashes,
   read-only preflight, historie en drie onafhankelijke push-CI-runs.
3. `GitHubAudit` verbruikt een echt tijdelijk SQLite-pogingjournal vóór de
   atomaire auditmutatie. Alleen de GitHub-API-grens is een fake; de provider,
   blobhash-/policychecks en nieuwe receiptvalidatie zijn de echte code.
4. `RestoreSafeLedger` verifieert het externe claimbewijs vóór iedere OVH-stap.
5. `RemoteLedger(SSHTransport(...))` construeert het echte gesigneerde protocol
   en controleert echte responses. Een expliciete lokale runner vervangt SSH;
   synthetische codehashes en hostkey bewijzen geen echte OVH-identiteit.
6. In verplichte CI voert die runner de echte PHP-stdiofunctie, HMAC-handler,
   PDO-store en MySQL-transacties uit tegen uitsluitend de disposable lokale
   `claim_ci`-container. De andere variant gebruikt een lokaal SQL-model.
7. `GitHubDispatch.send_once` bouwt de echte vaste HTTPS-request met uitsluitend
   release-ID en exacte bevestiging. Alleen de HTTP-opener is vervangen; er
   wordt geen echte workflowdispatch verstuurd.

De PHP-testharness is uitsluitend een testbestand: vaste loopback-DSN,
`claim_ci`, gebruiker `ci` en literal disposable CI-wachtwoord. Geen datasource
uit productieconfig of secrets. Hij vereist een expliciete testflag. Zijn reset
wist uitsluitend de twee geïsoleerde claimtesttabellen; hij wordt nooit naar
OVH gekopieerd of als runtime/deploybestand gebruikt. Dit is geen productie-
restoreprocedure of migratietoestemming.

## Foutgevallen over componentgrenzen

Dezelfde tests draaien met SQL-model én echte PHP/MySQL:

- Verkeerde webhook-HMAC, afwijkend onafhankelijk comment, gefaalde MySQL-push-CI
  en ontbrekend journal bereiken geen auditmutatie, SSH-runner of dispatch.
- Onzekere auditmutatie zonder commit blijft na opnieuw openen van het journal
  geblokkeerd; ook lege externe readback verleent geen retryrecht.
- Codehashdrift verbruikt de auditclaim maar verzendt geen claimpayload.
- Verloren SQL-commitack laat een echt gecommitteerde reservering achter, zonder
  dispatch of tweede poging. Een ongeldige response-HMAC autoriseert evenmin.
- Ownerdrift na SQL-reservering stopt vóór dispatch. Een SQL-ABORTED-status
  maakt de onafhankelijke auditclaim of het pogingjournal niet vrij.
- Onzekere dispatch of verloren laatste SQL-commitack na HTTP 204 geeft geen
  tweede POST. De parentclaim blijft `DISPATCH_UNKNOWN`; een run-kandidaat is
  geen bewezen dispatchidentiteit, live readback of productieacceptatie.
- Wissen van de geïsoleerde SQL-claimtabellen na succes simuleert rollback:
  de auditclaim en het pogingjournal voorkomen een tweede claim/dispatch.

Lokaal mag uitsluitend de echte PHP/MySQL-variant worden overgeslagen als de
expliciete CI-flag ontbreekt. In CI is `OVH_CLAIM_REQUIRE_FULLCHAIN=1` verplicht;
een ontbrekende PHP, kapotte datasource of overgeslagen verbindingstest faalt.
Tests starten geen SSH-, GitHub- of OVH-sessies en bewijzen geen live provisioning.

## Wat nog onafhankelijk moet worden bewezen

| Onderdeel | Code-/testbewijs | Nog ontbrekend voor activatie |
| --- | --- | --- |
| Eigenaar en releasebinding | Echte parsers/readers tegen adversarial fixtures | Verse exacte productieconsent en authentieke live evidence |
| Privé-auditrepo en CAS | Provider, races, canonieke historie en no-retry getest | Bestaand repo-ID, echte root/policy, ondersteund privéplan, beperkte aparte writer en live CAS-/retentiebewijs |
| Duurzaam pogingjournal | Echte SQLite, onafhankelijke processen en crash/herstart getest | Gekozen beschermde runtime/opslag buiten OVH-restore en ephemeral runners; fsync/pad-/toegangsisolatie |
| Private OVH-route | Protocol, hashgate, SSH-opties en echte PHP-stdio getest | Gepinde echte hostkey/host/user/poort, beperkt keygebruik, PHP/PDO-pad en immutable private code buiten webroot |
| Claimtabellen | Echte geïsoleerde MySQL 8.4-transacties en rollback getest | Read-only inspectie van echte OVH-schema/versie, minimale rechten, back-up-/herstelreview en aparte migratiebeslissing |
| Runtime en credentials | Geen automatische loaders of activatie | Afzonderlijk beoordeelde configuratie/provisioning; geen brede eigenaarcredential als fallback |
| Deploy en acceptatie | Bestaande gates blijven intact | Exact manifest/main, verse pinned remote hashes/drift, back-up/rollback, onafhankelijke readback en browseracceptatie |

Dit is geen multi-release scheduler: één globale poging blijft permanent
verbruikt. Geen reset, unlock, automatische volgende release of geactiveerde
writer. De eigen Mac is gekozen; echte opslagisolatie en de concrete
installatie/configuratie zijn nog niet bewezen. Groene CI vult dat bewijs niet in.

Een merge betreft alleen deze test-/documentatie-/CI-wijziging. Auditresources,
SSH/read-only live inspectie, SQL, credentials/rechten, runtimebootstrap,
activatie en productie-uitrol behouden hun afzonderlijke exacte autorisatie.

Details: [private SSH](ovh-controller-private-ssh.md),
[onafhankelijke audit en pogingjournal](ovh-controller-independent-audit.md),
[OVH-claimadapter](ovh-controller-claim-adapter.md).
