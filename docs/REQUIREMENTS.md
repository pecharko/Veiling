# Functionele requirements — veilingcatalogus 41 Lokeren

**Baseline:** 17 oktober 2026 · **Status:** statische broncode-inspectie uitgevoerd; databaseconstraint en `lots`-schema bevestigd op 18 oktober 2026; overige acceptatietests nog open · **Beheer:** GitHub, samen met de code. Zie [eerste code-audit](CODE_AUDIT_2026-10-17.md).

## Doel en scope

De applicatie beheert en publiceert de online catalogus van een **fysieke** veiling van 41 Lokeren. Thema: *Kunst, Lifestyle & Vintage — Veiling 2027*. Datum, locatie en goed doel worden later definitief ingevuld. De catalogus maakt **geen online biedingen** mogelijk.

Dit document beschrijft functies die in de huidige GitHub-broncode zijn aangetroffen, of eerder werden besproken. **Statisch bevestigd** betekent nooit dat de functie op productie getest is.

## Statusdefinities

- **Gerapporteerd** — blijkt alleen uit eerdere projectbesprekingen, gedeelde code of screenshots; nog niet in huidige repository nagekeken.
- **Statisch bevestigd** — implementatie is in de huidige GitHub-broncode aangetroffen; werking is nog niet met een test aangetoond.
- **Te verifiëren** — functie/veiligheidsvoorwaarde nog onvoldoende gecontroleerd.
- **Gepland** — goedgekeurde requirement, nog niet ontwikkeld.
- **Geverifieerd** — code én acceptatietest gecontroleerd; datum/testbewijs registreren.
- **Vervallen** — bewust verwijderd, met reden en wijzigingsdatum.
- **Tijdelijk uitgeschakeld** — op verzoek niet toegankelijk; herstelbare implementatie blijft in de Git-geschiedenis.

## Register functionele requirements

| ID | Requirement | Acceptatiecriterium (samenvatting) | Status |
|---|---|---|---|
| FR-001 | Beveiligd aanmelden bij admin | Alleen aangemelde beheerders kunnen beheeracties uitvoeren; foutieve pogingen worden beheerst. | Statisch bevestigd |
| FR-002 | Kavels aanmaken, wijzigen en verwijderen | Geldige gegevens worden bewaard; verwijderen vereist bevestiging. | Statisch bevestigd |
| FR-003 | Unieke lotnummers | Dezelfde lotnummerwaarde kan niet bij twee kavels voorkomen; begrijpelijke foutmelding bij duplicaat. | Te verifiëren |
| FR-004 | Invoervalidatie kavels | Titel verplicht; lotnummer positief en binnen bereik; minimum- en maximumschatting geldig en logisch. | Statisch bevestigd |
| FR-005 | Concept/publicatiestatus kavels | Conceptkavels worden niet publiek getoond; gepubliceerde kavels wel wanneer catalogus zichtbaar staat. | Statisch bevestigd |
| FR-006 | Foto's uploaden bij kavels | JPEG/PNG/WebP-upload werkt met servervalidatie en limieten; fouten worden gemeld zonder onnodig gegevensverlies. | Statisch bevestigd |
| FR-007 | Foto's tonen en verwijderen | Kavelfoto's verschijnen in admin en op publieke pagina's; verwijderactie is beschermd en doelgericht. | Statisch bevestigd |
| FR-008 | Hoofdfoto bepalen | De foto met de laagste sorteerwaarde wordt als hoofdfoto gebruikt. | Statisch bevestigd |
| FR-009 | CSV-import van kavels | **Voorlopig niet beschikbaar:** geen menu/dashboardlink, rechtstreekse GET/POST naar `/admin/import.php` stopt met HTTP 404 zonder datamutatie; oude implementatie later herstelbaar via Git. | Tijdelijk uitgeschakeld — geverifieerd op OVHcloud 18-10-2026 (T-042): GET/lege POST beide HTTP 404 in run 39109055677; gebruikersscreenshot bevestigt afwezigheid in menu én dashboard, links Foto-import en Back-up & herstel aanwezig |
| FR-010 | Bulkimport van kavelfoto's | Meerdere foto's kunnen via de foto-import aan juiste kavels worden gekoppeld. | Statisch bevestigd |
| FR-011 | Veilinginformatie beheren | Naam, ondertitel, inleiding, datum, tijd, locatie, adres, e-mail en praktische informatie kunnen worden opgeslagen. | Statisch bevestigd |
| FR-012 | Zichtbaarheid volledige catalogus | Beheerder kan de publieke catalogus aan/uitzetten, onafhankelijk van status per kavel. | Statisch bevestigd |
| FR-013 | Publieke catalogus en kavelpagina | Bezoeker kan zichtbare kavels en hun informatie/foto's bekijken zonder beheerrechten. | Statisch bevestigd |
| FR-014 | Offline biedproces | Publieke informatie vermeldt dat bieden uitsluitend tijdens de fysieke veiling gebeurt; geen online biedinterface. | Statisch bevestigd |
| FR-015 | Handmatige snapshot maken | Beheerder kan op verzoek een consistente back-up van relevante MySQL-gegevens en kavelfoto's maken. | Basiswerking in CI geslaagd (T-011/T-045, 18-10-2026); consistentie bij gelijktijdige writes nog te bewijzen |
| FR-016 | Snapshot downloaden en verwijderen | Back-up is downloadbaar; verwijderen vereist passende bevestiging en autorisatie. | Geïsoleerde HTTP-tests geslaagd (T-045): geauthenticeerd downloaden, foute bevestiging weigeren, gekozen ZIP verwijderen |
| FR-017 | Snapshot herstellen | Beheerder kan een geldige snapshot kiezen; expliciete bevestiging, voorafgaande veiligheidskopie en foutafhandeling beschermen bestaande data. | Basisherstel en veiligheidskopie getest in geïsoleerd CI (T-012/T-014/T-045); concurrency/crash/rollbackfouten nog open |
| FR-018 | Snapshotgroottes tonen | Per snapshot en totaal is de bestandsomvang leesbaar. | Backendformat/lijst/totalen en UI-rendering in CI bevestigd (T-011/T-045); fysieke hosting nog niet vergeleken |
| FR-019 | Opslaggebruik tonen en waarschuwen | Geschat hostinggebruik, snapshotruimte en fotogebruik worden getoond; vanaf 80% waarschuwing, vanaf 90% kritiek. | T-016 geslaagd in geïsoleerde CI-run 39126901647 (9 niveautests bij grenswaarden 80%/90%); echte OVHcloud-quota blijven indicatief, scherm is geen officiële teller; nieuwe functie nog niet op productie uitgerold |
| FR-020 | Capaciteitscontrole voor snapshot | Nieuwe snapshot wordt niet gestart wanneer de vooraf geschatte benodigde ruimte inclusief veiligheidsmarge ontoereikend is. | T-017 geslaagd in geïsoleerde CI-run 39126901647; minder dan de vereiste 20 MiB marge wordt geweigerd, evenals onvolledige opslagscan. Geen ZIP/DB-mutatie bij weigering. Capaciteitsfix nog niet op productie gedeployed of met echte hostinglimiet geverifieerd. |
| FR-021 | Administratiemenu inclusief back-upfunctie | Back-up & herstel is bereikbaar vanuit alle beheerpagina's. | Statisch bevestigd |
| FR-022 | CSRF-beveiliging voor schrijfacties | Iedere admin-POST die gegevens wijzigt valideert een CSRF-token. | Statisch bevestigd |
| FR-023 | Status- en foutmeldingen | Bij bewaren, import, verwijderen en herstel geeft de UI duidelijke feedback zonder gevoelige foutdetails te lekken. | Statisch bevestigd |

## Extra requirements ontdekt bij inspectie van de broncode

| ID | Requirement | Acceptatiecriterium | Status |
|---|---|---|---|
| FR-024 | Publieke zoekfunctie, filters, sortering en paginering | Zoekresultaten tonen alleen gepubliceerde kavels; filters, sortering en paginering behouden correcte resultaten. | Statisch bevestigd |
| FR-025 | Favorieten bewaren per browser | Bezoeker kan kavels als favoriet markeren en terugvinden via browseropslag; geen account of serveropslag nodig. | Statisch bevestigd |
| FR-026 | Fotogalerij en delen van kavel | Thumbnails wisselen de hoofdfoto; kavel-link delen/kopiëren werkt met browserfallback. | Statisch bevestigd |
| FR-027 | Kaveloverzicht in admin | Overzicht toont aantallen, statussen, foto's, zoeken/filteren en pagineren voor de beheerder. | Statisch bevestigd |
| FR-028 | Systeemcontrole | Admin ziet controles van PHP, MySQL, configuratie, HTTPS en schrijfrechten, met duidelijke resultaten. | Statisch bevestigd |
| FR-029 | Downloadbaar CSV-voorbeeld | Niet zichtbaar of downloadbaar vanuit de admin zolang FR-009 is uitgeschakeld; voorbeeld-CSV uit actieve Git-tree verwijderd. | Tijdelijk uitgeschakeld |
| FR-030 | Veilige, handmatig gestarte OVH-deployment | Beheerder kan vanuit GitHub op `main` eerst een releaseplan inzien en daarna vijf vooraf goedgekeurde PHP-bestanden via SFTP deployen; met hostkeycontrole, SFTP-secrets, vóór-uploadback-up, hashverificatie en niet-destructieve HTTP-smoketests. Geen automatische deploy na commit en geen secrets in Git. | Geverifieerd voor eerste gerichte release — SFTP-upload, back-upartifact, SHA256-controle en GET/POST 404 geslaagd in run 39109055677 op 18-10-2026; toekomstige releases alleen na aangepaste allowlist/review |
| FR-031 | Afzonderlijk goedgekeurde kaveleditor-deployment | Alleen `private/lot_form.php` en `catalogus-public/admin/kavel.php` mogen na merge via een apart gestart GitHub Actions-proces met SFTP-hostkeyverificatie, pre-back-up en SHA256-verificatie worden geplaatst; ontbrekende helper wordt in de back-upmanifest expliciet geregistreerd en bij rollback verwijderd. CSV-import blijft uitgeschakeld. | Uitgerold naar OVHcloud op 18-10-2026: GitHub Actions-job `ovh-kavel` geslaagd; pre-back-up, privé artifact, twee bestanduploads en anonieme smoke-tests groen (T-044). Beheerder meldt op 18-10-2026 dat editor goed lijkt te werken, na succesvolle visuele controle 'Nieuw kavel'; geen destructieve productietests uitgevoerd |
| FR-032 | Expliciete T-016/T-017 deployment | Gebruiker kan gecontroleerd precies `private/snapshots.php` en `catalogus-public/admin/backup.php` via een gerichte GitHub Actions/SFTP-job publiceren, met read-only plan, expliciet bevestigingswoord, verifieerbare codeback-up, checksums, rollback en veilige bereikbaarheidstests. Geen productie-snapshots of MySQL muteren. | Voorbereid in PR; CI en echte deployment afzonderlijk te bevestigen (T-046). |

**CSV-voorbeeld 18-10-2026 (FR-029):** Chrome toont bij de downloadpoging `voorbeeld-kavels.html` en `File wasn't available on site`; het in `admin/import.php` genoemde bestand ontbrak in de GitHub-repository. `catalogus-public/assets/voorbeeld-kavels.csv` is nu toegevoegd en gecontroleerd op de negen importkolommen, puntkommascheiding en UTF-8-BOM. **Nog vereist:** upload naar OVHcloud en geslaagde browserdownload bevestigen (T-028). De twee fictieve records zijn expliciet als voorbeelden gemarkeerd en staan op `publiceren=nee`.

**Nieuwe beslissing 18-10-2026:** op expliciet verzoek is **de volledige kavel-CSV-import tijdelijk buiten gebruik** gezet, inclusief de oude directe import-URL en het voorbeeldbestand. Foto-import blijft beschikbaar. De eerdere CSV-downloadbevinding hierboven is historisch; downloadherstel is geen openstaand werk zolang de functie uitgeschakeld blijft. Zie [uitrolinstructies](DEPLOYMENT.md) en test T-042.

**Bekende verificatiegaten:** bij FR-003 is de UNIQUE-index op `lots.lot_number` op 18 oktober 2026 via phpMyAdmin-metadata bevestigd; interactietests van de kaveleditor, CSV-upsert en gelijktijdige invoer blijven open. Bij FR-015 t/m FR-020 zijn consistentie, hersteltest en serverquota nog niet aangetoond. Zie het [code-auditrapport](CODE_AUDIT_2026-10-17.md). Een status **Statisch bevestigd** is geen geslaagde acceptatietest.

## Regressiecontrole fase 1 — 18 oktober 2026

De broncode van **FR-001** (login/CSRF), **FR-002/004** (kavelbeheer en validatie) en **FR-009** (CSV-import) is gericht nagekeken. De PHP-implementatie is aanwezig, maar de geauthenticeerde en muterende testscenario's zijn **niet uitgevoerd**; zie [REGRESSION_2026-10-18.md](REGRESSION_2026-10-18.md).

**Historische bevinding en opgelost defect (FR-002/004/006):** de oudere `admin/kavel.php` verloor ingestuurde formulierwaarden bij een opslaanfout en controleerde de uploadstatus pas na de lot-INSERT/UPDATE. Deze defecten zijn aangepakt via [PR #7](https://github.com/pecharko/Veiling/pull/7), op 18-10-2026 uitgerold en tegen een geïsoleerde database gevalideerd (T-038/T-039). Er zijn geen destructieve productietests voor uitgevoerd; [issue #5](https://github.com/pecharko/Veiling/issues/5) is afgesloten na positieve gebruikersfeedback.

**Kaveleditorcorrectie (PR #7, productie-uitrol bevestigd 18-10-2026):** FR-002/004 behoudt ingestuurde formulierwaarden wanneer de server opslaan weigert; FR-006 valideert de PHP-uploadstatus vóór de lot INSERT/UPDATE. Bij latere foutieve afbeeldingsinhoud blijft de bestaande melding over gedeeltelijk succes behouden. T-038 en T-039 zijn op geïsoleerde MySQL/PHP bevestigd: 20 HTTP/integratiechecks en 13 unit-asserties geslaagd.

**Integratietests kaveleditor (18-10-2026):** de PR #7-correctie is via een tijdelijke MySQL 8.4-database en lokale PHP 8.2-server gevalideerd in [CI-run 39109809899](https://github.com/pecharko/Veiling/actions/runs/39109809899): foutieve schattingen, dubbele lotnummers, behoud van ingezonden velden, voorcontrole van te grote uploads, geldige afbeeldingsupload en gedeeltelijke opslagwaarschuwing bij ongeldige beeldinhoud. Geen productiegegevens aangeraakt. **Productie-uitrol op 18-10-2026 technisch geslaagd. De beheerder heeft na de visuele nieuw-kavelcontrole bevestigd dat alles goed lijkt te werken; dit is gebruikersacceptatie, geen afzonderlijk bewijs voor alle muterende productieflows.**

## Uitgewerkte acceptatiecriteria voor risicovolle functies

### FR-003 — Unieke lotnummers

**Controlebewijs 18-10-2026:** aangeleverde phpMyAdmin-uitvoer van `SHOW INDEX FROM lots;` toont index `lot_number`, `Column_name=lot_number`, `Seq_in_index=1`, `Non_unique=0`. Daarmee is databank-uniciteit bevestigd. FR-003 blijft **Te verifiëren** tot de functionele tests voor duplicaatmeldingen, import en gelijktijdige bewerkingen zijn uitgevoerd. Geen schemawijziging nodig.

**Extra controlebewijs 18-10-2026:** `SELECT lot_number, COUNT(*) AS aantal FROM lots GROUP BY lot_number HAVING COUNT(*) > 1 ORDER BY lot_number;` leverde volgens het aangeleverde phpMyAdmin-screenshot **nul rijen** op: er zijn geen bestaande dubbele lotnummers (T-030 geslaagd). De interactieve functionele testen blijven open.

**Schema vastgelegd 18-10-2026:** de volledige DDL van `lots` uit `SHOW CREATE TABLE lots;` is gedocumenteerd in [DATABASE.md](DATABASE.md). T-031 is geslaagd. De databaselaag van FR-003 is bevestigd, maar de requirement blijft **Te verifiëren** voor kaveleditor-/CSV- en gelijktijdige bewerkingstests.

**Fotorelatie bevestigd 18-10-2026 (FR-007/FR-008/FR-010):** `SHOW CREATE TABLE lot_images;` toont foreign key `fk_lot_image (lot_id) REFERENCES lots(id) ON DELETE CASCADE`, fotovolgorde-index `idx_img (lot_id,sort_order,id)` en een unieke index `uq_source (lot_id,source_name)` voor niet-NULL bronbestandsnamen. DDL is opgenomen in [DATABASE.md](DATABASE.md); T-032 (schemacontrole) geslaagd. De praktijk van cascadeverwijdering en fysieke bestandsopruiming blijft te testen (T-033).
1. Een lotnummer is uniek op databaseniveau, niet alleen via een UI-controle.
2. Bewerken van een bestaande kavel met hetzelfde eigen lotnummer blijft mogelijk.
3. Een poging om een bestaand nummer te gebruiken geeft een begrijpelijke melding.
4. Ook importpaden respecteren dezelfde uniciteit.
5. Concurrerende bewerkingen kunnen geen dubbel nummer bewaren.

**Veilinginstellingen bevestigd 18-10-2026 (FR-011/FR-012):** `SHOW CREATE TABLE settings;` toont alle benodigde veilingvelden, een optionele datum en `is_published tinyint(1) NOT NULL DEFAULT 0`. De applicatie gebruikt `settings.id=1`; de primaire sleutel maakt die ID uniek, maar de database dwingt geen singleton af. De schema-inspectie T-034 is geslaagd; functionele formuliertest T-010 en publicatiecontrole T-006 zijn nog niet uitgevoerd. Zie [DATABASE.md](DATABASE.md).

**Beheerdersschema bevestigd 18-10-2026 (FR-001):** `SHOW CREATE TABLE admins;` bevestigt `id` als primaire sleutel, een unieke index op `email` en een verplichte `password_hash`-kolom. DDL staat in [DATABASE.md](DATABASE.md); T-035 is geslaagd. Er zijn geen rol-/permissiekolommen; rechtenbeheer en functionele login-/wachtwoordtests blijven open. De kolomnaam wijst op opslag van hashes, maar de DDL alleen bewijst geen correct hashbeleid.

**Loginpogingen-schema bevestigd 18-10-2026 (FR-001):** `SHOW CREATE TABLE login_attempts;` bevestigt `id` als primaire sleutel, `ip_hash CHAR(64) ASCII`, een timestamp en index `idx_attempts(ip_hash,attempted_at)`. De code in `admin/login.php` registreert mislukte pogingen, gebruikt maximaal 8 pogingen binnen 15 minuten en schoont oude records op bij een login-POST. T-036 (schema) is geslaagd; T-037 (werkend blokkeren, vrijgeven en opschonen) staat open. Deterministische IP-hashes moeten als mogelijk persoonsgegeven worden behandeld. Zie [DATABASE.md](DATABASE.md).

**Functionele snapshotproeven 18-10-2026:** twee geïsoleerde GitHub Actions-suites tegen MySQL 8.4 + PHP 8.2 (40/40 geslaagd, [run 39112431040](https://github.com/pecharko/Veiling/actions/runs/39112431040)). Getest: ZIP met uitsluitend databank-JSON, checksummanifest en kavelfoto's (géén PHP/CSS/JS-sourcecode); terugzetten van vijf tabellen/foto's, checksumweigering, snapshot-lock, opslagoptelling, geauthenticeerde download, CSRF, correcte bevestigingen, verwijderen, veiligheidssnapshot en sessie-invalidering. **Beperking:** geen productierestore; consistente snapshot bij gelijktijdige bewerkingen, crashrecoverability en werkelijke OVHcloud-quota blijven open onder [issue #2](https://github.com/pecharko/Veiling/issues/2).

### FR-015 t/m FR-017 — Snapshot en herstel
1. De snapshot omvat alle tabellen en kavelfoto's die nodig zijn om de catalogus volledig te herstellen; exact bereik wordt gecontroleerd.
2. Snapshotbestanden bevinden zich buiten de publiek toegankelijke webroot.
3. Een snapshot kan worden gedownload en buiten OVHcloud bewaard.
4. Het systeem verifieert integriteit, versie/compatibiliteit en aanwezigheid van nodige onderdelen vóór herstel.
5. Voor herstel wordt een veiligheidskopie van de huidige toestand gemaakt.
6. De beheerder moet een ingrijpend herstel expliciet bevestigen.
7. Bij mislukking worden half herstelde gegevens of verweesde foto's zoveel mogelijk voorkomen; herstelstrategie moet aantoonbaar getest zijn.
8. Het herstelproces krijgt een test met een tijdelijke/testomgeving **vóór** gebruik op productie.

**Aanpassing in PR #11 (18-10-2026):** de opslagniveau-classificatie is nu een gedeelde functie voor UI en tests (80%=waarschuwing, 90%=kritiek). De opslagpreflight controleert ook bij waarschuwingen of de actuele schatting betrouwbaar genoeg is; een onvolledige bestandsmeting verhindert nu uit voorzorg een nieuwe snapshot. Een expliciete interne quota-parameter maakt de capaciteitstest in een disposable CI-database mogelijk zonder de OVHcloud-hosting werkelijk te vullen. **GitHub-code is niet automatisch de gedeployde productiecode.**

### FR-019 en FR-020 — Hostingopslag
1. Dashboard maakt het onderscheid tussen geschatte websitebestanden, snapshots, foto's en eventuele afzonderlijk toegewezen databasequota.
2. Geen dubbele telling van snapshots in de totale schatting.
3. Een waarschuwing verschijnt bij >=80%; een kritieke melding bij >=90% van de ingestelde limiet.
4. De getoonde limiet is instelbaar of gedocumenteerd; de OVHcloud Manager is leidend bij afwijkingen.
5. Controle van ruimte vóór snapshot voorkomt voorzienbare opslagoverschrijding; afwijkend OVH-quota wordt veilig behandeld.

**Beveiligingscontrole 18-10-2026 (FR-001 / NFR-007):** na een geldige aanmelding werd `/admin/index.php` zichtbaar (T-001B). Een eerder screenshot toonde rood **'Not Secure'**, maar een later Chrome-beveiligingspaneel voor **41lokeren.be** vermeldt expliciet **'Connection is secure'** en **'Certificate is valid'**. De TLS-/certificaatcontrole is bevestigd. Aanvankelijk gaf `curl -I http://41lokeren.be/admin/login.php` **HTTP 200 zonder redirect**, maar na de tijdelijke Apache-wijziging toont de herhaalde test **`302 Found` met een correcte `Location: https://41lokeren.be/admin/login.php`**. De redirect werkt voor deze route. **Aanvullend bevestigd:** HTTPS-login `HTTP/2 200`, loginredirectketen `302`→`200` zonder lus en publieke homepage `302`→HTTPS. **Update:** de definitieve `301 Moved Permanently` is op 18-10-2026 met curl aangetoond voor de HTTP-loginpagina en homepage, telkens naar hun HTTPS-equivalent. **T-041 geslaagd (18-10-2026):** gebruiker ziet na logout én bij opnieuw openen van `/admin/index.php` het loginformulier. **NFR-007 nog deels open:** andere adminroutes, technische cookie-/sessiestorecontrole ; de gedeelde permanente `.htaccess`-redirectconfiguratie staat inmiddels onder versiebeheer op `catalogus-public/.htaccess`. Zie [issue #6](https://github.com/pecharko/Veiling/issues/6) en [herstelplan](HTTPS_REMEDIATION.md).

## Niet-functionele afspraken

| ID | Afspraak | Verificatie |
|---|---|---|
| NFR-001 | Geheimen, database-inhoud, persoonsgegevens en foto's niet in GitHub committen. | Code-review plus `.gitignore`-controle |
| NFR-002 | PHP + MySQL blijven compatibel met huidige OVHcloud-hosting. | Staging-/productiesmoke-test |
| NFR-003 | Beheerinterface is bruikbaar op desktop én kleinere schermen. | Visuele en toetsenbordcontrole |
| NFR-004 | Database en foto-back-ups worden onafhankelijk van Git getest en extern bewaard. | Gedocumenteerde restoretest |
| NFR-005 | Een release kan worden teruggedraaid met code-back-up en passend dataplan. | Deployment-checklist |
| NFR-006 | Vereiste functionaliteit en tests worden in dezelfde codewijziging bijgewerkt. | Pull-request-checklist |
| NFR-007 | Beheeromgeving uitsluitend via correct HTTPS bereikbaar, met valide certificaat en veilige cookies; HTTP wordt naar HTTPS omgeleid. | **Gedeeltelijk aangetoond 18-10-2026:** Chrome valideert HTTPS-certificaat en HTTP-login geeft na aanpassing tijdelijke `302` naar HTTPS. directe HTTPS-login, redirectketen zonder lus en definitieve 301 voor login/homepage bevestigd; overige adminroutes en sessiecookie-/logoutgedrag nog open (T-040/T-041). |

## Traceerbaarheid en onderhoud

- Bewaar per requirement de ID ongewijzigd. Gebruik nieuwe ID's voor nieuwe functies.
- Vermeld in commits/PR's de betrokken FR-/NFR-ID's.
- Voeg bij status **Geverifieerd** een testdatum, omgeving, bestandsverwijzing en uitkomst toe.
- Bij een bug: registreer afwijking, herstel de code en actualiseer zo nodig acceptatiecriteria/testplan.
- Verander **Gerapporteerd** pas in **Geverifieerd** na broncode-inspectie én werkende test.
- Controleer dit register bij elke wijziging en minstens vóór elke productie-uitrol.

## Eerste verificatieronde

Nu de actuele OVHcloud-broncode naar GitHub is geïmporteerd en statisch beoordeeld, controleren we vervolgens op de server en in een testomgeving achtereenvolgens: databaseconstraints, alle importpaden, foto-opslag, login/CSRF, back-up/restore, de menukoppeling, opslagmeter en de zichtbaarheid van de publieke catalogus. Noteer bevindingen in `docs/TESTPLAN.md` en dit register.
