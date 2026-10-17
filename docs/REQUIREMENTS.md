# Functionele requirements — veilingcatalogus 41 Lokeren

**Baseline:** 17 oktober 2026 · **Status:** voorlopige inventaris, nog niet tegen de volledige productiebroncode gecontroleerd · **Beheer:** GitHub, samen met de code.

## Doel en scope

De applicatie beheert en publiceert de online catalogus van een **fysieke** veiling van 41 Lokeren. Thema: *Kunst, Lifestyle & Vintage — Veiling 2027*. Datum, locatie en goed doel worden later definitief ingevuld. De catalogus maakt **geen online biedingen** mogelijk.

Dit document beschrijft bestaande of eerder besproken functies. Het label **Gerapporteerd** betekent uitsluitend dat een functie eerder is gebouwd/besproken of in de beheeromgeving werd getoond; het is **geen bevestiging** dat die nu volledig werkt op productie.

## Statusdefinities

- **Gerapporteerd** — blijkt uit eerdere projectbesprekingen, gedeelde code of screenshots; te verifiëren na import en test.
- **Te verifiëren** — functie/veiligheidsvoorwaarde nog onvoldoende gecontroleerd.
- **Gepland** — goedgekeurde requirement, nog niet ontwikkeld.
- **Geverifieerd** — code én acceptatietest gecontroleerd; datum/testbewijs registreren.
- **Vervallen** — bewust verwijderd, met reden en wijzigingsdatum.

## Register functionele requirements

| ID | Requirement | Acceptatiecriterium (samenvatting) | Status |
|---|---|---|---|
| FR-001 | Beveiligd aanmelden bij admin | Alleen aangemelde beheerders kunnen beheeracties uitvoeren; foutieve pogingen worden beheerst. | Gerapporteerd |
| FR-002 | Kavels aanmaken, wijzigen en verwijderen | Geldige gegevens worden bewaard; verwijderen vereist bevestiging. | Gerapporteerd |
| FR-003 | Unieke lotnummers | Dezelfde lotnummerwaarde kan niet bij twee kavels voorkomen; begrijpelijke foutmelding bij duplicaat. | Gerapporteerd |
| FR-004 | Invoervalidatie kavels | Titel verplicht; lotnummer positief en binnen bereik; minimum- en maximumschatting geldig en logisch. | Gerapporteerd |
| FR-005 | Concept/publicatiestatus kavels | Conceptkavels worden niet publiek getoond; gepubliceerde kavels wel wanneer catalogus zichtbaar staat. | Gerapporteerd |
| FR-006 | Foto's uploaden bij kavels | JPEG/PNG/WebP-upload werkt met servervalidatie en limieten; fouten worden gemeld zonder onnodig gegevensverlies. | Gerapporteerd |
| FR-007 | Foto's tonen en verwijderen | Kavelfoto's verschijnen in admin en op publieke pagina's; verwijderactie is beschermd en doelgericht. | Gerapporteerd |
| FR-008 | Hoofdfoto bepalen | De foto met de laagste sorteerwaarde wordt als hoofdfoto gebruikt. | Gerapporteerd |
| FR-009 | CSV-import van kavels | Beheerder kan kavels importeren; invoerfouten en duplicaten worden beheerst afgehandeld. | Gerapporteerd; importcontroles te verifiëren |
| FR-010 | Bulkimport van kavelfoto's | Meerdere foto's kunnen via de foto-import aan juiste kavels worden gekoppeld. | Gerapporteerd |
| FR-011 | Veilinginformatie beheren | Naam, ondertitel, inleiding, datum, tijd, locatie, adres, e-mail en praktische informatie kunnen worden opgeslagen. | Gerapporteerd |
| FR-012 | Zichtbaarheid volledige catalogus | Beheerder kan de publieke catalogus aan/uitzetten, onafhankelijk van status per kavel. | Gerapporteerd |
| FR-013 | Publieke catalogus en kavelpagina | Bezoeker kan zichtbare kavels en hun informatie/foto's bekijken zonder beheerrechten. | Gerapporteerd |
| FR-014 | Offline biedproces | Publieke informatie vermeldt dat bieden uitsluitend tijdens de fysieke veiling gebeurt; geen online biedinterface. | Gerapporteerd |
| FR-015 | Handmatige snapshot maken | Beheerder kan op verzoek een consistente back-up van relevante MySQL-gegevens en kavelfoto's maken. | Gerapporteerd; integriteit nog te testen |
| FR-016 | Snapshot downloaden en verwijderen | Back-up is downloadbaar; verwijderen vereist passende bevestiging en autorisatie. | Gerapporteerd |
| FR-017 | Snapshot herstellen | Beheerder kan een geldige snapshot kiezen; expliciete bevestiging, voorafgaande veiligheidskopie en foutafhandeling beschermen bestaande data. | Gerapporteerd; hersteltest ontbreekt |
| FR-018 | Snapshotgroottes tonen | Per snapshot en totaal is de bestandsomvang leesbaar. | Gerapporteerd |
| FR-019 | Opslaggebruik tonen en waarschuwen | Geschat hostinggebruik, snapshotruimte en fotogebruik worden getoond; vanaf 80% waarschuwing, vanaf 90% kritiek. | Gerapporteerd; quota-inschatting te valideren |
| FR-020 | Capaciteitscontrole voor snapshot | Nieuwe snapshot wordt niet gestart wanneer de vooraf geschatte benodigde ruimte inclusief veiligheidsmarge ontoereikend is. | Gerapporteerd; grensgevallen te testen |
| FR-021 | Administratiemenu inclusief back-upfunctie | Back-up & herstel is bereikbaar vanuit alle beheerpagina's. | Gerapporteerd; hotfix v1.5.1 te verifiëren |
| FR-022 | CSRF-beveiliging voor schrijfacties | Iedere admin-POST die gegevens wijzigt valideert een CSRF-token. | Gerapporteerd; code-audit nodig |
| FR-023 | Status- en foutmeldingen | Bij bewaren, import, verwijderen en herstel geeft de UI duidelijke feedback zonder gevoelige foutdetails te lekken. | Gerapporteerd; code-audit nodig |

## Uitgewerkte acceptatiecriteria voor risicovolle functies

### FR-003 — Unieke lotnummers
1. Een lotnummer is uniek op databaseniveau, niet alleen via een UI-controle.
2. Bewerken van een bestaande kavel met hetzelfde eigen lotnummer blijft mogelijk.
3. Een poging om een bestaand nummer te gebruiken geeft een begrijpelijke melding.
4. Ook importpaden respecteren dezelfde uniciteit.
5. Concurrerende bewerkingen kunnen geen dubbel nummer bewaren.

### FR-015 t/m FR-017 — Snapshot en herstel
1. De snapshot omvat alle tabellen en kavelfoto's die nodig zijn om de catalogus volledig te herstellen; exact bereik wordt gecontroleerd.
2. Snapshotbestanden bevinden zich buiten de publiek toegankelijke webroot.
3. Een snapshot kan worden gedownload en buiten OVHcloud bewaard.
4. Het systeem verifieert integriteit, versie/compatibiliteit en aanwezigheid van nodige onderdelen vóór herstel.
5. Voor herstel wordt een veiligheidskopie van de huidige toestand gemaakt.
6. De beheerder moet een ingrijpend herstel expliciet bevestigen.
7. Bij mislukking worden half herstelde gegevens of verweesde foto's zoveel mogelijk voorkomen; herstelstrategie moet aantoonbaar getest zijn.
8. Het herstelproces krijgt een test met een tijdelijke/testomgeving **vóór** gebruik op productie.

### FR-019 en FR-020 — Hostingopslag
1. Dashboard maakt het onderscheid tussen geschatte websitebestanden, snapshots, foto's en eventuele afzonderlijk toegewezen databasequota.
2. Geen dubbele telling van snapshots in de totale schatting.
3. Een waarschuwing verschijnt bij >=80%; een kritieke melding bij >=90% van de ingestelde limiet.
4. De getoonde limiet is instelbaar of gedocumenteerd; de OVHcloud Manager is leidend bij afwijkingen.
5. Controle van ruimte vóór snapshot voorkomt voorzienbare opslagoverschrijding; afwijkend OVH-quota wordt veilig behandeld.

## Niet-functionele afspraken

| ID | Afspraak | Verificatie |
|---|---|---|
| NFR-001 | Geheimen, database-inhoud, persoonsgegevens en foto's niet in GitHub committen. | Code-review plus `.gitignore`-controle |
| NFR-002 | PHP + MySQL blijven compatibel met huidige OVHcloud-hosting. | Staging-/productiesmoke-test |
| NFR-003 | Beheerinterface is bruikbaar op desktop én kleinere schermen. | Visuele en toetsenbordcontrole |
| NFR-004 | Database en foto-back-ups worden onafhankelijk van Git getest en extern bewaard. | Gedocumenteerde restoretest |
| NFR-005 | Een release kan worden teruggedraaid met code-back-up en passend dataplan. | Deployment-checklist |
| NFR-006 | Vereiste functionaliteit en tests worden in dezelfde codewijziging bijgewerkt. | Pull-request-checklist |

## Traceerbaarheid en onderhoud

- Bewaar per requirement de ID ongewijzigd. Gebruik nieuwe ID's voor nieuwe functies.
- Vermeld in commits/PR's de betrokken FR-/NFR-ID's.
- Voeg bij status **Geverifieerd** een testdatum, omgeving, bestandsverwijzing en uitkomst toe.
- Bij een bug: registreer afwijking, herstel de code en actualiseer zo nodig acceptatiecriteria/testplan.
- Verander **Gerapporteerd** pas in **Geverifieerd** na broncode-inspectie én werkende test.
- Controleer dit register bij elke wijziging en minstens vóór elke productie-uitrol.

## Eerste verificatieronde

Na veilige import van de actuele OVHcloud-broncode controleren we achtereenvolgens: databaseconstraints, alle importpaden, foto-opslag, login/CSRF, back-up/restore, de menukoppeling, opslagmeter en de zichtbaarheid van de publieke catalogus. Noteer bevindingen in `docs/TESTPLAN.md` en dit register.
