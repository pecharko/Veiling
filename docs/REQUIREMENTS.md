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
| FR-009 | CSV-import van kavels | **Voorlopig niet beschikbaar:** geen menu/dashboardlink, rechtstreekse GET/POST naar `/admin/import.php` stopt met HTTP 404 zonder datamutatie; oude implementatie later herstelbaar via Git. | Tijdelijk uitgeschakeld — geverifieerd op OVHcloud 18-10-2026 (T-042): GET/lege POST beide HTTP 404 in run 39109055677; gebruikersscreenshot bevestigt afwezigheid in menu én dashboard, op dat moment links Foto-import en Back-up & herstel aanwezig (historische waarneming) |
| FR-010 | Bulkimport van kavelfoto's (niet ondersteund) | **Definitief geschrapt:** geen admin-menulink; directe GET en POST naar `/admin/fotos-bulk.php` geven HTTP 404 zonder mutaties. Bestaande data onder `private/photo-inbox/` blijven onaangeroerd; individuele fotoupload per kavel blijft beschikbaar. | **Technisch geverifieerd op productie 19-10-2026:** eerdere retirement-release en opnieuw [auditrun #39203389883](https://github.com/pecharko/Veiling/actions/runs/39203389883), GET en lege POST HTTP 404; menu door beheerder eerder visueel bevestigd (T-047). |
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

**Nieuwe beslissing 18-10-2026:** op expliciet verzoek is **de volledige kavel-CSV-import tijdelijk buiten gebruik** gezet, inclusief de oude directe import-URL en het voorbeeldbestand. Foto-import was op 18-10-2026 nog beschikbaar, maar is op 19-10-2026 definitief uit de scope gehaald (FR-010/T-047). De eerdere CSV-downloadbevinding hierboven is historisch; downloadherstel is geen openstaand werk zolang de functie uitgeschakeld blijft. Zie [uitrolinstructies](DEPLOYMENT.md) en test T-042.

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

## Statusupdate auditproductierelease — 19 oktober 2026

- **FR-001 / FR-022 (beheerder, CSRF):** POST+CSRF-uitloggen is geïmplementeerd en geslaagd in geïsoleerde HTTP-tests van PR #27. [Productierun #39203389883](https://github.com/pecharko/Veiling/actions/runs/39203389883) verifieert het nieuwe `admin/logout.php` met SHA-256 en de anonieme redirect (HTTP 303). **Gebruikersacceptatie 19-10-2026:** na uitloggen/heropenen-checklist bevestigt de beheerder dat alles werkt (T-049); geen afzonderlijke geauthenticeerde productietest uitgevoerd door CI.
- **FR-006 / FR-007 / FR-013 (fotoloze kavels):** nieuwe `assets/placeholder.svg` was vóór release niet aanwezig en is daarna met SHA-256 gecontroleerd; publiek HTTPS-verzoek geeft HTTP 200. **Gebruikersacceptatie 19-10-2026:** na de visuele checklist voor een fotoloos kavel bevestigt de beheerder dat alles werkt (T-028); geen afzonderlijk screenshot van die rendering.
- **FR-010:** vervallen bulkfoto-import blijft HTTP 404 voor GET en lege POST; gebruikerscontrole van het adminmenu werd al bij de vorige retirement-release bevestigd (T-047).
- **NFR-005 / NFR-006:** release van zes vaste runtimepaden op commit `8b2e6b8f444f67952620163c523e04c5807a032e` uitgevoerd. GitHub-run `39203389883` is groen, met driftcontrole, read-backchecksums en privé rollbackartifact `ovh-audit-pr25-26-27-before-39203389883` (tot 26-10-2026). De daadwerkelijke rollback is **niet** op productie getest. Database, uploads, kavelfoto's en snapshots zijn niet gewijzigd door de workflow.

## Onderzoek snapshotconsistentie en herstelcrashes (19 oktober 2026)

Bij [issue #2](https://github.com/pecharko/Veiling/issues/2) onderzoeken de geïsoleerde tests T-050A/T-050B de grenzen van FR-015/FR-017. De huidige `snap_create()` leest tabellen zonder consistente DB-readtransactie of applicatiebrede fotolock; `snap_restore()` wisselt eerst de uploadmap, daarna commit hij databasegegevens. De voorbereiding reproduceert naar verwachting een gemengde snapshot en een inconsistent crashvenster. **Dit is nog geen productieherstel of oplossing.** Bestaande capaciteitstests FR-019/020 blijven ongewijzigd en moeten opnieuw getest worden bij een toekomstig crashbestendig ontwerp met tijdelijke extra opslag. Zie [onderzoeksdocument](SNAPSHOT_CONSISTENCY_CRASH_STUDY_2026-10-19.md).

## Deeloplossing: consistente MySQL-leesweergave bij snapshots (in review)

De snapshotfunctie wordt aangescherpt zodat de vijf InnoDB-tabellen via een eigen `REPEATABLE READ`-transactie vanaf één DB-leesmoment worden geëxporteerd. T-050A verwacht na wijziging dat een gelijktijdige atomaire update **niet** als gemengde rijcombinatie verschijnt; de test controleert daarnaast transactieafsluiting en afwijzing van een al actieve oproeperstransactie. **Nog niet gemerged of live uitgerold.** Dit lost gelijktijdige wijziging/verwijdering van *bestanden* niet op en verandert niets aan de herstelprocedure met een procescrash (T-050B). FR-015 is dus slechts voor database-rijen verbeterd; FR-017 blijft open onder issue #2. Opnames met concurrente fotomutaties en crashjournal volgen afzonderlijk.

## Fase 2 — gecoördineerde adminwrites en snapshots (in review)

Voor [issue #2](https://github.com/pecharko/Veiling/issues/2) bevat de nieuwe fase-2-PR een MySQL `GET_LOCK`-mutex met vaste naam, gedeeld tussen `snap_create()`, `snap_restore()` en **alle op dit moment bekende app-routes** die een snapshot-tabel of kavelfoto wijzigen: `admin/kavel.php`, `admin/instellingen.php`, `admin/login.php`. Een gewone admin-POST wacht maximaal vijf seconden op een bezette mutex en wordt dan zonder gegevensmutatie met HTTP 503 geweigerd. Snapshots/restores wachten maximaal twintig seconden. Het beschermde traject omvat DB-export, foto-archivering, en een voorafgaande veiligheidssnapshot plus daaropvolgend herstel onder dezelfde buitenste mutex.

De mutex is verbindinggebonden op dezelfde MySQL-server, zodat de coördinatie niet afhankelijk is van lokale bestandslocks op meerdere PHP-hosts. **Beperkingen:** handmatige SFTP-fotowijzigingen, directe phpMyAdmin-mutaties en externe scripts die de mutex niet gebruiken vallen buiten de bescherming; publieke GETs kunnen tijdens een restore nog een tijdelijk gemengde toestand zien. Bij SIGKILL na de fotoswap blijft het database-/fotomapcrashvenster bestaan (T-050B). Er is nog geen duurzame hersteljournal of onderhoudsmodus. Fase 2 is geen afgeronde FR-017-veiligheidsgarantie en mag niet zonder aparte goedgekeurde release op productie.

T-051 controleert twee concurrerende MySQL-verbindingen, re-entrant lockverdeling, automatisch vrijgeven bij een gestorven PHP-proces, en het fail-closed gedrag van kavel-POST. FR-015 is hiermee sterker voor **applicatiegecoördineerde** foto- en DB-schrijfacties; externe writes en crashherstel blijven open.

**Incidentrunbook:** [RESTORE_INCIDENT_RUNBOOK.md](RESTORE_INCIDENT_RUNBOOK.md) beschrijft welke read-only bewijzen nodig zijn voordat iemand een onderbroken herstel mag hervatten of de onderhoudsblokkering opheffen.

## Fase 3A — privéhersteljournal en fail-closed onderhoud (in review)

De beperkte fase-3A-wijziging voegt een privé `.restore-active.json` toe onder `private/backups/`. De engine schrijft vóór de eerste fotoswap een journal met willekeurige run-ID, snapshotnaam en checksum, oude/nieuwe mapidentifiers en fase; faseovergangen worden met een tijdelijk bestand en dezelfde-directory `rename()` gepubliceerd. Een resterend journal, een achtergebleven oude fotomap (ook zonder journal) of een halfaangemaakt tijdelijk journalbestand schakelt alle PHP-instappunten die `private/bootstrap.php` laden over op een neutrale HTTP 503-onderhoudspagina. Nieuwe snapshots en restores worden bij een onbeoordeelde marker geweigerd. Normaal herstel of volledige rollback van een afgehandelde pre-commit-fout ruimt de marker op.

Dit is **fail-closed incidentbeperking**, nog **geen automatische crashrecovery**. Bij uitval tussen fotoswap en MySQL-commit kan de actieve fotomap nog afwijken van MySQL; de PHP-site toont die combinatie niet als werkende catalogus, maar bestaande statische URLs naar `uploads/`, caches, buitenapp-mutaties en requests die al bezig waren vallen buiten de eenvoudige PHP-gate. `fsync` van de journalfile wordt gebruikt waar beschikbaar, maar directory-durability bij een volledige hosting-/storagecrash kan op gedeelde hosting niet worden gegarandeerd. De beheerder heeft geen geautomatiseerde herstart- of rollbackactie in de UI; deskundige analyse met de originele database, het herstelarchief en de tijdelijk bewaarde mappen blijft noodzakelijk.

T-052/T-053/T-054 zijn geïsoleerd geslaagd in [Snapshot-CI-run #39324527061](https://github.com/pecharko/Veiling/actions/runs/39324527061): journal/HTTP 503 na SIGKILL, een corrupt of halfgeschreven journal, achtergebleven fotomappen, gecontroleerd hervatten na CI-only reparatie, volledige pre-commit-rollback en een onduidelijke journalfase direct na geslaagde databasecommit. De gevreesde **onbekende commituitkomst** houdt het journal bewust actief in plaats van op basis van een vermoeden oude of nieuwe foto's weg te gooien. FR-017 is pas volledig geslaagd nadat de volgende fase een geverifieerde, idempotente herstel-/recoveryprocedure en toegangsbeheersing oplevert. **Niet uitgerold op productie; geen live restoretests.**

## Fase 3B — alleen-lezen incidentdiagnose (in review)

Voor issue #2 komt een extra privé-inspectiemodule `private/restore_diagnosis.php` en een uitsluitend via PHP CLI te starten tool `scripts/restore_diagnose.php --plan`. Deze gebruikt geen productiedata in CI, schrijft geen database- of fotobestand en **kan geen restore, rollback of onderhoudsmarker verwijderen**. De module vergelijkt de volledige feitelijke inhoud van alle vijf snapshot-MySQL-tabellen en alle `uploads/`-foto's met de ZIP. De ZIP wordt met de SHA-256 uit het veilige journal vergeleken; ook alle daadwerkelijke ZIP-fotobytes worden gecontroleerd, niet enkel hun manifest. Symlinks, vreemde bestanden, corrupt journal en onvolledige journalupdates zorgen voor fail-closed beoordeling. Het rapport bevat uitsluitend statusvelden, afwijkende *tabelnamen* en aantallen, geen gevoelige persoonsgegevens, wachtwoorden of bestandsinhoud.

De diagnose geeft bewust **geen groen licht voor automatische herstelacties**. Zelfs `target_verified_pending_review` betekent alleen dat de huidige MySQL-rijen en fotobytes overeenkomen met het doelarchief; het journal en de onderhoudsblokkering blijven actief totdat een verantwoordelijke ook de externe veiligheidskopieën, toegangsrechten, lopende verzoeken en eventuele buitenapp-mutaties heeft beoordeeld. `mixed_or_unknown_keep_maintenance` en `blocked_unverified` verplichten dezelfde blokkering. Een `clear`-uitkomst betekent *alleen* dat er geen journal/oudemap-indicator werd gevonden, niet dat de hele dataset gecontroleerd of veilig is.

De geïsoleerde regressies T-055, T-050B en T-054 bewijzen het onderscheid tussen processchade vóór commit, een volledige doeltoestand na commit, en afwijkende/gewijzigde archieven of foto's. **Niet op OVHcloud uitgerold; automatische/operator-autorisatiegestuurde herstelacties blijven voor een afzonderlijke fase.**

## Fase 3C — expliciet goedgekeurde afronding van volledig herstelde toestand (in review)

Een operator kan alleen nadat fase-3B-diagnose **de volledige doeldatabase én alle actieve foto's byte-voor-byte gelijk aan een gecontroleerd doelarchief heeft aangetoond**, een herstel incident afsluiten. De nieuwe privéhelper `private/restore_finalize.php` en de afzonderlijke CLI `scripts/restore_finalize.php` bevatten **geen SQL-herstel, foto-overschrijving, fotoverwijdering of automatische rollback**. `--plan` levert uitsluitend een status en een SHA-256-challenge, gebonden aan journalrun-ID, exacte doel-/veiligheids-ZIP-hashes, operatorcode, journalfase en het bewaarde fotobewijs. De `--finalize`-actie mag alleen via CLI onder een bevoegde operatoraccount, met die challenge, expliciete Nederlandse toestemmingszin, afzonderlijke verklaring dat de **externe** veiligheidskopie door de operator gecontroleerd is en de daaruit berekende SHA-256. Deze hash moet exact gelijk zijn aan de gecontroleerde safety-ZIP op de server; een attestatiezin alleen is onvoldoende.

De actieroute neemt dezelfde snapshot-`flock` en MySQL-advisorylock, verifieert het plan opnieuw, bewaart de originele fotomap door een **rename op hetzelfde filesystem** naar een afgeschermde locatie onder `private/backups/`, verifieert dat die fotobytes identiek zijn aan de aangewezen pre-restore veiligheids-ZIP en controleert de doeldatabase en doel-foto's **opnieuw**. Ze schrijft een beperkt private auditreceipt; pas daarna wordt het hersteljournal verwijderd. Wanneer PHP tussentijds wordt gestopt, blijft het journal staan; bij een nieuwe herstelpoging moet een **nieuw plan** en een nieuwe handmatige goedkeuring volgen. Achtergebleven oude foto's worden niet automatisch verwijderd.

**Restrictie:** dit is uitsluitend *finalisatie* van een reeds bewezen correct afgeronde restore (bijvoorbeeld SIGKILL vlak ná MySQL-commit). Een crash vóór commit met gemengde DB/foto's kan hierdoor **niet** worden hersteld; die blijft in onderhoud voor afzonderlijke, deskundig geautoriseerde reconstructie. Rechtstreekse wijzigingen via SQL/SFTP, storagecrashes, statische foto-URL's en niet bewezen externe kopieën blijven risico's. De verklaring over een externe back-up is een menselijke attestatie, geen door CI geverifieerde cloudback-up. **Geen nieuwe productie-uitrol, live herstel of automatische toestemming vanuit deze PR.** T-056 toetst een post-commit-SIGKILL, verkeerd token, gewijzigde database, niet-overeenkomende externe back-up-SHA, ontbrekende kopiebevestiging, gemanipuleerd receipt, processtop tijdens evidencepreservering, bewaarde voorherstel-foto's en herhaalde uitvoering op wegwerpfoto's. **25 geïsoleerde controles geslaagd** in [Snapshot-CI-run #39372914703](https://github.com/pecharko/Veiling/actions/runs/39372914703).

## Fase 3D — gecontroleerd herstel van originele fotomap na pre-commit-crash (in review)

Voor een afgebakend onderbroken herstel waarbij **de volledige actuele InnoDB-database nog overeenkomt met de afzonderlijke veiligheids-ZIP van vlak vóór de restore**, maar de actieve foto's al zijn vervangen door de doelfoto's, biedt `private/restore_precommit.php` een beperkte, te autoriseren fotoherstelactie. Het is nadrukkelijk **geen generieke SQL-restore of automatische rollback**. De functie vergelijkt alle vijf database-tabellen, de integriteit van beide ZIP-archieven, alle interne ZIP-fotohashes en de volledige huidige en oude fotobestanden met hun juiste ZIP. Een onzeker databasecommit, ontbrekende originele foto's, onbekende fotomutaties, verweesde stagingmap of niet-overeenkomende veiligheidskopie stopt onmiddellijk met behoud van onderhoud.

Een operator gebruikt uitsluitend een CLI-plan `scripts/restore_precommit.php --plan` en daarna, **in een aparte handmatige autorisatiestap**, een exact aan journalrun-ID, doel-/veiligheidschecksum, huidige fotofase en operator gebonden code. De CLI eist eveneens een exact overeenkomende SHA-256 van de *extern bewaarde* safety-ZIP plus expliciete attestatie van de operator. Tijdens uitvoering neemt hij zowel het bestaande exclusieve snapshotbestandlock als de MySQL-catalogusmutex. De huidige doelfoto's worden met een rename op hetzelfde filesystem naar een privébewaarmap verplaatst (**niet verwijderd**); daarna wordt de originele fotomap vanuit de journal-identiteit opnieuw actief. De database wordt **niet** gewijzigd. Na beide filesystemmutaties verifieert het proces opnieuw alle tabellen en fotobytes, schrijft een privé auditreceipt en wist het journal als allerlaatste stap.

De toestanden `initial`, `target_privately_preserved` en `original_reactivated` kunnen alleen met een nieuw plan en nieuwe toestemming worden hervat na een SIGKILL. T-057 heeft dit met **22 geïsoleerde controles** aangetoond in [Snapshot-CI-run #39374712561](https://github.com/pecharko/Veiling/actions/runs/39374712561); geen gebruik van productiegegevens. **Resterend risico:** een database die noch volledig bij de veiligheids-ZIP, noch bij de doelsnapshot past, kan hiermee niet worden gerepareerd en blijft geblokkeerd voor afzonderlijk deskundig onderzoek. Directe SFTP/SQL-wijzigingen, reeds lopende publieke verzoeken, statische foto-URLs en volledige storagecrashes worden niet volledig afgedekt. Dit is geen volledige FR-017-oplossing en is niet op productie geïmplementeerd.


## Fase 3E — diagnostiek voor een werkelijk gemengde databank/fototoestand (in review)

De voorgaande herstelmodules mogen niet zelfstandig een MySQL-toestand repareren die niet exact overeenkomt met de oude veiligheids-ZIP óf de bedoelde doel-ZIP. `private/restore_mixed_diagnosis.php` voegt daarom uitsluitend een **forensische, niet-mutatieve vergelijking** toe. Ze controleert een actief restorejournal, journalgebonden doel-ZIP-SHA-256, het afzonderlijke veiligheidsarchief, feitelijke SHA-256 van alle ZIP-foto's, vijf MySQL-tabellen in **één consistent readmoment ten opzichte van beide referenties**, en vier mogelijke foto-evidencemappen. Een tweede databasescan en extra controle van de actieve fotobytes en beide ZIP-bestanden kunnen veranderingen gedurende de diagnose aantonen; onverwachte symlinks, journaltempbestanden of ontbrekend bewijs doen de diagnose uit voorzorg stoppen.

De per-tabel classificatie `both`, `safety_only`, `target_only` of `neither` toont **alleen tabelnamen en aantallen**, nooit adminwachtwoorden, e-mailadressen, kaveltitels of IP-hashes. `scripts/restore_mixed_diagnose.php --plan --safety-snapshot NAAM` is **uitsluitend CLI en read-only**; er zijn geen restore-, SQL-import-, journal-clear- of fotowisselopties. Alle uitkomsten, ook een volledige oude/nieuwe toestand, vereisen dat de HTTP-onderhoudsstatus actief blijft tot afzonderlijke deskundige review en autorisatie. Extern bewaarde safety-ZIP's kunnen op basis van alleen hun serverhash niet als daadwerkelijk offsite bewezen worden beschouwd.

**Nog open in [issue #2](https://github.com/pecharko/Veiling/issues/2):** echt SQL-herstel bij een gemengde database, niet-coöperatieve directe database/SFTP-mutaties, volledige hostcrash-durability, statische foto's en al lopende verzoeken tijdens onderhoud. De diagnose is geen herstelprocedure en wordt niet op OVHcloud uitgerold. **T-058 geslaagd** met 16 geïsoleerde controles in [Snapshot-CI-run #39376629673](https://github.com/pecharko/Veiling/actions/runs/39376629673), waaronder gemengde tabellen, derde onbekende toestand, gewijzigde onbetrouwbare foto-evidence en weigering van muterende CLI-opties. Dit bewijst geen veilige SQL-reconstructie.


## Traceerbaarheid en onderhoud

- Bewaar per requirement de ID ongewijzigd. Gebruik nieuwe ID's voor nieuwe functies.
- Vermeld in commits/PR's de betrokken FR-/NFR-ID's.
- Voeg bij status **Geverifieerd** een testdatum, omgeving, bestandsverwijzing en uitkomst toe.
- Bij een bug: registreer afwijking, herstel de code en actualiseer zo nodig acceptatiecriteria/testplan.
- Verander **Gerapporteerd** pas in **Geverifieerd** na broncode-inspectie én werkende test.
- Controleer dit register bij elke wijziging en minstens vóór elke productie-uitrol.

## Eerste verificatieronde

Nu de actuele OVHcloud-broncode naar GitHub is geïmporteerd en statisch beoordeeld, controleren we vervolgens op de server en in een testomgeving achtereenvolgens: databaseconstraints, alle importpaden, foto-opslag, login/CSRF, back-up/restore, de menukoppeling, opslagmeter en de zichtbaarheid van de publieke catalogus. Noteer bevindingen in `docs/TESTPLAN.md` en dit register.

## FR-031 — Modern kaveloverzicht (gepland voor productie)
Het beheer toont vier statistiekkaarten, een responsieve kaveltabel met statusbadges en een bewerkactie. De dubbele actiebalk verdwijnt omdat de hoofdnavigatie deze acties al aanbiedt. Zoekterm, categorie en publicatiestatus kunnen gecombineerd worden; paginering bewaart de filters. De backend blijft read-only op het overzicht en gebruikt gebonden SQL-parameters.
