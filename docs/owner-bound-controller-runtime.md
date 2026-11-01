# Controller-runtime — dormant serviceverpakking

**Actuele hostingkeuze:** de eigenaar beschikt alleen over bestaande
OVH-webhosting. De hieronder beschreven Python/systemd-variant is daarom geen
gekozen productieruntime. De passende vervolgvoorbereiding staat in
[OVH-claimadapter](ovh-controller-claim-adapter.md); alle ingangen blijven uit.

Dit voorstel vervolgt PR #516 zonder afhankelijk te zijn van een reeds gekozen
hostnaam. Ontwerpkeuze: **één afzonderlijke controllerhost met persistente lokale
opslag**. De huidige ontwikkelcontainer en tijdelijke GitHub Actions-runners
zijn geen live controllerhost. Er wordt geen machine, endpoint, webhook,
credential, ledger of service aangemaakt, geïnstalleerd of gestart.

## Geleverde service-ingang

`scripts/owner_bound_dispatch_service.py` bevat een WSGI-reviewadapter die via
de standaard HTTP/WSGI-interface lokaal testbaar is. De productie-export
`application()` weigert ieder verzoek met HTTP 503 / `DISABLED`, vóór het lezen
van body, credentials, opslag of externe evidence. De CLI opent geen listener,
leest geen secrets en stopt met exitcode 78. Environmentflags kunnen dit niet
wijzigen. De bestaande dispatch-`execute()` blijft eveneens uitgeschakeld.

De afzonderlijke `ReviewApplication`-seam is uitsluitend read-only:

1. Alleen HTTPS, POST op `/hooks/github/owner-bound-review`, JSON/UTF-8 en een
   expliciete begrensde Content-Length tot 128 KiB. Geen queryparameters,
   routingprefix, compressie of chunked framing. `X-Forwarded-Proto` verleent
   geen HTTPS-vertrouwen; de vertrouwde WSGI-server moet het echte scheme zetten.
2. Verifieer `X-Hub-Signature-256` met HMAC-SHA-256 over de **exacte ruwe body**
   en constant-time vergelijking vóór JSON-verwerking of GitHub-GETs. De
   afzonderlijke webhookkey moet onafhankelijk met minstens 32 random bytes
   worden geprovisioneerd. Hij is geen GitHub API-token.
3. Weiger dubbele JSON-properties, verkeerd event/action/repository-ID,
   pull-requestcomments, ongeldige IDs, bots en uiteenlopende eigenaaridentiteit.
   De vaste repository-ID is `1423110590` (`pecharko/Veiling`). De gewone
   ownerapprovaltekst plus random nonce blijft verplicht.
4. Haal alle bestaande owner/main/manifest/source/remote/preflight/push-CI-
   evidence opnieuw op via de bestaande `GitHubEvidence`. Een `BoundReader`
   vergelijkt bij **iedere** prepare-pass opnieuw owner-ID, issue/comment-ID
   en SHA-256 van het exacte comment uit het ondertekende event met GitHub.
   Een geldige MAC vervangt onafhankelijke eigenaarverificatie niet.
5. Het antwoord bevat alleen een bindingdigest en vaste statusvelden. Geen
   nonce, approvalbody, persoonsgegevens, signing key, API-token of ruwe fout.
   `dispatch_started`, `write_allowed`, `retry_allowed` en
   `production_accepted` blijven altijd false. Geen ledger- of dispatchaanroep.

GitHub ondertekent de body, **niet** delivery-ID of eventheaders. De delivery-ID
is daarom nooit de consentnonce of replayregistratie. Een geldig oud event kan
opnieuw worden aangeboden; de onafhankelijke 24-uurschecks gelden telkens.
Herhaalde reviews wijzigen geen staat. Een toekomstige dispatcher moet daarnaast
de duurzame nonce/release/consent-reservering en volledige tweede verificatie
uit PR #516 blijven gebruiken. De tests verbinden die seams uitsluitend met
een fake API, tijdelijke SQL-opslag en een fake sender.

## Procesverpakking en operationele grens

`deploy/controller/owner-bound-review.service.example` is een niet geïnstalleerd
systemd-voorbeeld met een aparte niet-rootgebruiker, immutable versiedirectory,
strikt read-only filesystem, lege capabilities, geen privilegeverhoging en
`Restart=no`. Er is geen `[Install]`-sectie, poort, ingress, secretpad of
schrijfbare ledgerdirectory. `APPROVED_MAIN_SHA` is een expliciete placeholder.
Zelfs na kopiëren zou de huidige CLI uitsluitend `DISABLED` retourneren.

De toekomstige server/reverse proxy moet vóór aparte review concreet worden
gekozen en getoetst: private backend, exacte Host-allowlist, TLS,
request-/headertime-outs, maximale bodygrootte, veilige framing, rate limiting,
geen body/header/tokenlogging en geen automatische POST-retries. Gebruik geen
publieke ontwikkelserver. Het WSGI-contract is getest, geen live TLS/proxyconfig.

Scheiding van rollen: de reviewservice krijgt alleen GET-toegang; een later
apart geautoriseerde dispatcher krijgt uitsluitend de benodigde Actions-rechten
en gecontroleerde ledger-write. De reviewservice ontvangt nooit een dispatchkey.
Beide krijgen geen OVH-, SQL-, website-, ruleset- of secretbeheerdersrechten.
De template kent bewust nog geen credentials toe en configureert geen keyrotatie.

## Readiness en resterende externe evidence

Read-only gecontroleerd op 1 november 2026:

| Onderdeel | Waarneming / vereist vervolg |
| --- | --- |
| Bronbasis | `main` op `c62e8f7ac2d5c54fa0f2ad6391399a9f8143c230`, PR #516 gemerged; beide push-CI-runs geslaagd |
| Code-ingangen | Dispatch-`execute()`, WSGI-productie-export en CLI blijven onvoorwaardelijk disabled |
| Repo-policy | API meldde `main.protected=false`; twee zichtbare rulesets gelden alleen voor Phase-C-testbranches en -tags |
| Beschermingsdetails | Branch-protection uitlezen gaf 403; geen bewijs van effectieve productie-reviewgates |
| Huidige ontwikkelomgeving | Geen geconfigureerde runtimevariables, identities, secrets of controllercapabilities; dit bewijst niets over een toekomstige host |
| Doelhost | Nog geen geverifieerde host, runtimeprincipal, code-ownership of private ingress; geen live gebruik mogelijk |
| Ledger | Persistente fsync/locking, eigenaarschap, retentie en herstel op de echte host moeten onafhankelijk bewezen zijn |
| Anti-rollback | Een externe, afzonderlijk beschermde audit/high-water-authoriteit moet het terugzetten/vervangen van claimdata detecteren; lokale SQL-tests bewijzen dit niet |
| Credentials | Apart geprovisioneerde webhookkey en minimale GET/dispatchidentiteiten, met rotatie en toegangsscheiding, nog niet aangetoond |
| Run-correlatie | Bestaande target echoot geen nonce; kandidaat-run blijft onbevestigd en claim blijft geblokkeerd, zoals in PR #516 |

Deze tabel is geen actuele productieattestatie. Lees de feiten opnieuw vlak vóór
een eventueel activatiebesluit. Geen succesvolle lokale test, PR-CI of merge
verandert ontbrekende externe evidence in toestemming.

## Reviewbare volgorde voor een latere activatie

1. Review en merge uitsluitend dit dormant codevoorstel op exacte head/scope.
2. Leg echte host, supervisor/server/proxy, principals, immutable installatiepad,
   credentialvoorziening, persistente ledger en onafhankelijke anti-rollback-
   evidence vast. Hiervoor is afzonderlijk exact toepasselijke toestemming nodig.
3. Test eerst de read-only reviewroute op de gekozen host met synthetische data,
   zonder dispatcherkey, ledgerwrites of productie-workflowstart. Ook daarvoor
   geen endpoint of service installeren zonder toestemming.
4. Bereid pas daarna een concrete afzonderlijke activeringswijziging voor.
   Geen verborgen envflag, directe private-seam-aanroep of impliciete activatie.
5. Elke echte release behoudt eigen eigenaarconsent op repo/main/manifest/files/
   remote baselines, productieomgeving, pinned hostkey, fresh driftgate,
   back-up/rollback/readback en onafhankelijke OVH- en browseracceptatie.

Geen nieuwe productierelease, manifest, workflow-dispatch, infrastructuurwrite
of keyverstrekking hoort bij deze PR. Terugdraaien van dit voorstel betreft
alleen repositorycode; er is geen geïnstalleerde service om terug te draaien.
