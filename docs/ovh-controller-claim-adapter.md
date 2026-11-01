# OVH shared hosting — dormant claimadapter

De eigenaar bevestigde vervolgens SSH-beschikbaarheid. De concrete private
transportvoorbereiding staat in [Private SSH/CLI-route](ovh-controller-private-ssh.md).
Die code is eveneens dormant en verleent geen OVH-uitvoeringsbevoegdheid.

Randvoorwaarde van de eigenaar: **alleen bestaande OVH-webhosting**. Er is geen
aparte Python/systemd-controllerhost beschikbaar. Daarom is die servicevariant
geen gekozen productieroute. Dit voorstel levert de host-onafhankelijke
Python/PHP-claimkoppeling, met echte geïsoleerde MySQL-tests, zonder OVH te raken.

## Verdeling en harde grenzen

- GitHub Actions kan de bestaande Python-evidencecontroles uitvoeren. De
  eigenaar-, main-, manifest-, source/remote-, allowlist- en push-CI-gates uit
  PR #516 blijven ongewijzigd, inclusief de volledige tweede verificatie.
  `OvhEvidence` voegt een derde verplichte onafhankelijke `main`-push-CI-run toe:
  de daadwerkelijke PHP/MySQL-claimtests. De nieuwste run moet succesvol zijn,
  bij dezelfde repo/main horen en vóór het exacte ownerconsent zijn voltooid.
- Een **nog apart te beoordelen private uitvoerroute** kan de PHP-bibliotheek
  `scripts/ovh_controller_claim_store.php` bij OVH aanroepen. Er is geen URL,
  HTTP-route, daemon, webhook, cron of upload toegevoegd.
  URL-geheimhouding is geen authenticatie. De latere SSH-transportklasse wordt
  niet standaard geïnstantieerd of geconfigureerd.
- Alleen `reserve`, toegestane `transition` en `read` zijn mogelijk. Geen
  aangeleverde SQL, willekeurige tabel, workflow, ref of dispatchactie.
- De PHP-bibliotheek accepteert een expliciet verstrekte PDO-verbinding. Zij
  leest geen websiteconfig, bestanden, envcredentials of productiegegevens en
  opent zelf geen verbinding. Direct uitvoeren retourneert `DISABLED` (exit 78).
- `ovh_controller_claim_adapter.execute()` en alle bestaande publieke
  dispatch-/service-ingangen blijven onvoorwaardelijk uitgeschakeld.

## Eerst onafhankelijk reserveren, dan OVH-MySQL

`RestoreSafeLedger` heeft dezelfde reserve/transition/read-interface als de
ledger uit #516 en kan daardoor de bestaande `_attempt_once()`-seam hergebruiken.
De volgorde is strikt:

1. Valideer de volledige exacte binding: repository, numerieke eigenaar/issue/
   comment, release-ID, immutable main SHA, nonce, manifest- en consentdigest,
   alle source/remote triples en precies de drie vereiste push-CI-run-IDs.
2. Laat een **onafhankelijke externe atomaire autoriteit** eenmalig nonce,
   consent, release en actieve dispatchscope reserveren. Deze opslag mag niet
   terugrollen met OVH-back-ups, accountbestanden of website/MySQL-snapshots.
3. Alleen een nieuw toegekende, exact gebonden reserveringsreceipt mag één
   OVH-claimpoging laten volgen. Een gelezen oude receipt, boolean of JSON-
   bewijsveld geldt niet als nieuwe reservering. Onzekere ontvangst: stop.
4. Reserveer in één MySQL-transactie de bestaande parentclaim en de nieuwe
   companionbinding. Alle oude unieke comment/release/SHA-gates blijven gelden.
5. Controleer bij iedere latere statuswijziging opnieuw dat de onafhankelijke
   binding bestaat. Geen lokale fallback wanneer de autoriteit ontbreekt.
6. De bestaande controller herhaalt alle GitHub-evidencechecks en slaat
   `ATTEMPTED` duurzaam op vóór de ene dispatchpoging. Iedere onzekere externe
   reserve-, commit-, response- of POST-uitkomst blijft `STOP, NO RETRY`.

Er is **geen productie-implementatie of configuratie van die externe
autoriteit** toegevoegd. Dat is een echte activatieblokkade, geen bewijsboolean
die later op true gezet kan worden. De typed testreceipt is ook geen zelfstandig
trustcertificaat: de runtime moet exclusiviteit, identiteit, onveranderbaarheid,
retentie, herstelgedrag en beperkte rechten van de echte provider bewijzen.
Zonder provider blokkeert de adapter vóór iedere SQL-aanroep.

De externe reservering blijft ook verbruikt wanneer SQL daarna faalt, al
bestaat er nog geen SQL-claim. Dat kan handmatig herstelwerk vereisen, maar
voorkomt herhaalde dispatch na een onduidelijke uitkomst. Er is geen automatische
vrijgave, takeover, DELETE of retry-interface. Een eventuele gecontroleerde
volgende release vereist eerst een afzonderlijk beoordeelde lockvrijgave.

## Gesigneerd protocol en toestand

`RemoteLedger` verwacht alleen een expliciet ingespoten `exchange(body, mac)`-
transport. Canonieke JSON en HMAC-SHA-256 binden alle requestvelden. Request en
response gebruiken verschillende vaste domeinprefixen tegen reflectie.
Iedere response moet dezelfde operatie, random requestnonce en bindingdigest
hebben. Responses en requests zijn begrensd tot 128 KiB; dubbele JSON-velden,
onbekende velden/operaties en ongeldige statusovergangen blokkeren.

Requests zijn maximaal 300 seconden geldig, met maximaal 30 seconden tolerantie
voor een klok vóór issued_at. De bestaande vijfminuten-TTL is niet verlengd.
Dit protocol is dus **niet** rechtstreeks een uurlijkse OVH-cronqueue. Een cron-
of SFTP-handoff vereist de aparte transport-/timingreview uit
`b33-hourly-cron-handoff-feasibility.md`, geen impliciete invoering hier.

PHP verifieert de MAC, canonieke body, volledige binding, actie en geldigheid
vóór het construeren van de store. Een gesigneerde claimaanvraag authenticeert
de producer, niet zelfstandig een eigenaar of release; de producer moet de
bestaande onafhankelijke `GitHubEvidence`-gates afdwingen. SQL- of API-bewijs
mag nooit als bevoegdheid buiten de controller worden gebruikt.

De companiontabel bindt de nonce en volledige JSON/digest aan de bestaande
`controller_release_claims`-rij. Een gegenereerde unieke active_slot voorkomt
gelijktijdige actieve SQL-claims. Toegestane toestanden zijn RESERVED,
ATTEMPTED, ACCEPTED, UNKNOWN en ABORTED. ABORTED geeft uitsluitend het SQL-
activeslot vrij; release/nonce blijven verbruikt en de onafhankelijke autoriteit
geeft niets automatisch vrij. ACCEPTED betekent geen bevestigde workflowrun:
de bestaande parentstatus blijft DISPATCH_UNKNOWN. Run-correlatie blijft de
conservatieve kandidaat-reconciliatie uit #516.

## Databasevoorstel — niet toepassen

`database/controller_release_claim_bindings_proposal.sql` is een afzonderlijk
handmatig voorstel voor één companiontabel met foreign key naar de bestaande
claimtabel. De bestaande migratie en rijen blijven ongewijzigd; er worden geen
users, grants of schemawijzigingen toegepast. Oude claims zonder companion kunnen niet opnieuw worden
gereserveerd: de parent-UNIQUE-constraints blijven eerder blokkeren.

Vóór daadwerkelijke toepassing zijn onafhankelijk schema-/rechtenbewijs,
back-up/herstelplan, least privilege en afzonderlijke exacte DB-autorisatie
vereist. Het historische bericht dat de parenttabel op OVH bestaat is geen
actuele onafhankelijke schema-attestatie. Er is in deze taak geen live DB-query
of SFTP-actie uitgevoerd.

## Bewijs en volgende grens

Lokale Python-tests gebruiken expliciete audit-/SQL-testmodellen. Zij bewijzen
control flow en herstelblokkade, geen echte externe duurzaamheid. Verplichte
CI draait daarnaast echte PHP-protocoltests en MySQL 8.4 in een disposable
`claim_ci` op `127.0.0.1`. De testcode weigert elke andere DSN/gebruiker.

Getest: Python/PHP-canonieke/MAC-pariteit, responsebinding, verkeerde keys,
tijden, schema, IDs en paden; ontbreken/onzeker resultaat van de onafhankelijke
autoriteit; replay na SQL-restore; gelijktijdige PHP/PDO-processen; bestaande
legacyclaims; parent/companion-transactierollback; ongeldige toestandswijzigingen;
fout na een geslaagde COMMIT; en ontbreken van automatische retry of dispatch.
Een SQL-restoretest laat bewust zien dat **SQL alleen** replay opnieuw mogelijk
maakt. De onafhankelijke-wrappertest blokkeert juist die tweede poging.

Voor activatie blijven nodig: aantoonbare private OVH-uitvoering en account-
mogelijkheden, een echt onafhankelijk restore-safe CAS-backend, minimale aparte
credentials/rechten, authenticatie/rotatie en afzonderlijke exacte goedkeuring
van transport, DB, runtime en gates. De codevoorstel-PR is veilig reviewbaar
zonder die voorzieningen te installeren of hun beschikbaarheid te verzinnen.
