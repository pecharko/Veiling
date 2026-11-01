# OVH-controller — private SSH/CLI-route, dormant voorstel

De eigenaar heeft bevestigd dat SSH beschikbaar is op de bestaande OVH-hosting.
Dat selecteert een private CLI-route zonder publiek PHP-endpoint of uurlijkse
cronqueue. Die melding is nog geen onafhankelijk bewijs van login, PHP-pad,
bestandsrechten, sleutelisolatie of live uitvoerbaarheid. Deze wijziging maakt
geen SSH-sessie met OVH en configureert of uploadt niets.

## Transport en protocol

`scripts/ovh_controller_claim_ssh.py` levert een expliciet te construeren
`SSHTransport` voor de bestaande `RemoteLedger` uit PR #518. De publieke
`execute()` blijft altijd `DISABLED`; geen envflag, secrets-loader of workflow
installeert/configureert een live instantie.

1. Vertrouwde runtimeconfiguratie bindt exacte host/user/poort, identity-filepad,
   één exacte publieke pinned known-hostsregel, private root, immutable
   code-revisie, SHA-256 van beide PHP-codebestanden en een expliciet PHP-pad.
   Er zijn geen host/credential/PHP-defaults en geen velden uit een claimrequest
   kunnen SSH-doel, commando, identiteit of remote pad kiezen.
2. SSH gebruikt uitsluitend publickey-auth, expliciete identity, strikt gepinde
   hostkey en één verbindingspoging. Geen agent, wachtwoordfallback, forwardings,
   user-config, dynamische hostkey-update, DNS-TOFU, local command, proxycommand
   of hergebruikte controlsocket. SHA-1 hostkeysignatures worden niet toegevoegd.
3. Vóór claimdata wordt verzonden, draait uitsluitend het vaste read-only
   `sha256sum -- <executor> <store>` op de exacte twee versiepadbestanden.
   Alleen exacte hashes, volgorde, stdout en foutloze exit worden geaccepteerd.
   Mismatch, ontbrekend bestand, hostkeyfout of stderr stopt zonder claimpayload.
4. Daarna volgt hoogstens één vaste PHP-CLI-aanroep van de executor. De body en
   MAC gaan uitsluitend via stdin, nooit via argv, URL, remote command of logs.
   Er wordt niets geüpload, geïnstalleerd, gemigreerd of gedispatcht.
5. Stdin/stdout/stderr worden tegelijk afgehandeld met harde tijd- en bytegrenzen.
   De hashcontrole heeft 20 seconden totaal, de executor 30 seconden; stdout is
   begrensd en stderr wordt nooit teruggegeven. De serverseam begrenst ook
   eigen stdinleestijd en framelengte.
6. Elke fout na mogelijk verzenden is een onzekere uitkomst, **STOP, NO RETRY**.
   Een verbroken SSH-sessie bewijst niet dat PHP/MySQL niets heeft gecommit.
   De onafhankelijke reservering uit #518 blijft verbruikt.

SSH is de private vervoerslaag. Response-HMAC, random requestnonce, operatie,
bindingdigest en beperkte request-TTL uit #518 blijven apart gecontroleerd.
Een succesvol SSH-commando of codehash is geen ownerconsent of claimreceipt.

## Private PHP-executor

`scripts/ovh_controller_claim_executor.php` bevat een CLI-only stdio-seam die
alleen de bestaande canonieke gesigneerde claimrequests aan de bestaande
storefactory kan aanbieden. Er is geen config-/key-/PDO-loader geïnstalleerd.
Authenticatie en schema-/scope-/tijdchecks vinden plaats vóór de supplied store
wordt geconstrueerd. Fouten produceren alleen een vaste geredigeerde weigering.

**Direct uitvoeren van dit bestand blijft onvoorwaardelijk DISABLED, exit 78,**
zonder stdin te lezen of een datasource te openen. Een envflag verandert dit
niet. De tests gebruiken een afzonderlijke synthetische PHP-harness met fake
store; die harness wordt nooit als remote runtime of deploybestand aangeboden.
Er is geen publiek endpoint. Kopiëren van deze code activeert geen claimant.

## Code- en credentialisolatie zijn nog te bewijzen

De hashcontrole en executor zijn twee opeenvolgende SSH-aanroepen. De versie-
directory moet daarom aantoonbaar immutable zijn voor de runtimegebruiker en
beschermd tegen gelijktijdig vervangen, symlinks of writable parentdirectories.
Een hashcontrole alleen sluit het wijzigingsvenster tussen beide aanroepen niet
uit. Deze code claimt geen OS-isolatie die op gedeelde hosting niet bewezen is.

Een gewone accountbrede OVH-SSH-key kan meer dan twee commands uitvoeren en
mogelijk websitecode/secrets benaderen. De allowlist in deze client beperkt
**de client**, niet de sleutel op de server. Vóór activatie zijn een afzonderlijk
beperkte identiteit/key en aantoonbare serverbeperking tot deze twee exacte
commando's nodig, of een apart exact beoordeeld en aanvaard restrisico. Deze PR
wijzigt geen `authorized_keys`, accountrechten, shell, PHP-config of SSH-policy.

De bekende webrootnamen worden door de configvalidator geweigerd. Dat vervangt
niet het onafhankelijk controleren van de echte documentroot/aliases. Zowel
executor, store als eventuele toekomstige private config horen erbuiten.
Het exacte PHP-pad en de PDO/MySQL-extensie moeten op OVH read-only worden
geverifieerd; CI op PHP 8.2 is geen live hostingbewijs voor een ander pad/versie.

## Verificatie en activatiegrens

De tests openen geen SSH-verbinding. Een fake SSH-peer controleert volgorde,
pinningopties, commando-allowlist, payloadscheiding, foutpaden en ontbrekende
retry. Echte lokale subprocessen testen pipe-deadlock, outputlimieten en timeout.
`ssh -G` controleert uitsluitend lokale optieparsing; er is geen login.
CI draait daarnaast echte PHP stdio-protocoltests met synthetische data, inclusief
onvoltooide/te grote frames en de default-disabled ingang.

Een latere read-only OVH-controle vereist afzonderlijk exact toepasselijke
toestemming én geconfigureerde beperkte toegang. Er worden nu geen hostnames,
private keys, known-hostspins of productieconfig uit secrets gehaald. Daarna
blijven nodig: onafhankelijke audit/CAS-provider buiten OVH-herstel, concrete
schema/rechten/back-upreview, key/HMAC-provisioning, immutable installatie,
afzonderlijk geautoriseerde live bootstrap en ongewijzigde owner/main/manifest/
push-CI/SFTP-drift/rollback/readback/browsergates. Geen hiervan wordt stilzwijgend
door deze codevoorstel-PR ingeschakeld of verleend.
