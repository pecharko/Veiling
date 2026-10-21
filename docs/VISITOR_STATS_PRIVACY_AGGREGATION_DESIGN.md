# Ontwerp: privacyvriendelijke bezoekersstatistieken tot 31 oktober 2027

**Status:** VOORSTEL — geen migratie, codewijziging of toestemming voor productie.
**Organisator:** 41 Lokeren — 41clublokeren@gmail.com.
**Context:** gebruiker heeft ingestemd met voorbereiding van een oplossing waarbij de statistische campagneresultaten tot en met 31 oktober 2027 beschikbaar blijven, terwijl de per dag gepseudonimiseerde codes niet langer worden bewaard dan noodzakelijk. Bestaande wens: geen automatische opschoning bij bezoek en geen cron; verwijdering uitsluitend handmatig.

## Waarom wijziging nodig is

De bestaande tabel `visitor_stats_daily` met primaire sleutel (`visit_date`, `visitor_id`, `page_kind`, `lot_id`) bevat pseudonieme dagcodes. Het huidige dashboard gebruikt de afgelopen 30 UTC-dagen. Individuele codes tot oktober 2027 zijn voor seizoentotalen niet aangetoond noodzakelijk. De bestaande code kan geen anonieme seizoensresultaten archiveren. De tabel mag niet stilzwijgend worden gemigreerd of verwijderd.

## Beoogd gedrag

1. Registratie blijft privacybeperkt: geen trackingcookies, per UTC-dag wisselende HMAC, Do Not Track gerespecteerd, geen ruw IP of user-agent opgeslagen in `visitor_stats_daily`.
2. In de beveiligde adminconsole komt een **handmatige, expliciet bevestigde actie** 'Archiveer statistieken ouder dan 30 dagen'. Deze actie neemt de oude dagdetailrecords, berekent dag- en kavelaggregaten, valideert hun volledigheid, slaat ze duurzaam in aparte anonieme aggregaattabellen op, en verwijdert daarna pas de oorspronkelijke dagcodes. Geen automatische archivering/opruiming bij bezoekers of cron.
3. Aggregaten bevatten `visit_date`, `page_kind`, `lot_id`, `views` en `unique_visitor_days`, maar geen individuele `visitor_id`. Voor aantallen unieke bezoekersdagen over alle pagina's is een afzonderlijk dagtotaal nodig, omdat eenzelfde bezoeker verschillende kavels kan openen.
4. Voor kavel-overlap is optioneel een tweede anonieme dagtabel met `visit_date`, geordende kavels `lot_id_a < lot_id_b` en `shared_visitor_days` nodig. Deze kan bij handmatige archivering uit detailrecords worden berekend vóór verwijdering. Een cap en volumebeperking zijn noodzakelijk bij veel kavels; indien niet nodig, laat deze tabel weg en gebruik enkel kavelaggregaten. 'Unieke bezoekers' zijn dagelijks afgebakende schattingen, geen personen over meerdere dagen.
5. Bewaar anonieme aggregaten tot **31 oktober 2027**. Vanaf **1 november 2027** archiveert of verwijdert de aangewezen beheerder de cijfers conform een afzonderlijk vastgelegde eindprocedure. De adminfunctie moet expliciete preview + CSRF + tweede bevestiging vereisen. Voorkom dat nieuwe trackingrecords na de einddatum onbeheerd blijven binnenkomen: voor het einde van de campagne is een afzonderlijke geautoriseerde uitschakelingshandeling nodig.
6. Een dashboard moet over volledige campagne én recentste 30 dagen kunnen rapporteren door reeds gearchiveerde aggregaten en niet-gearchiveerde dagcodes zonder dubbeltelling te combineren.
7. Geen wijziging aan bestaande `visitor_stats_daily` of bestaande data-only snapshots zonder afzonderlijke evaluatie. Nieuwe tabellen/migraties uitsluitend na expliciete schema-authorisatie, eigen schema-review en tests. Eerst staging of geïsoleerde tests met lege database en testgegevens.

## Data-integriteit en herhaalbaarheid van handmatige archivering

- Per UTC-dag archiveren, nooit huidige nog actieve dag; standaard ouder dan 30 dagen. Valideer gekozen grensdatum en verbied toekomst.
- Gebruik database-transactie; lock relevante dagrecords, maak alle dagaggregaten idempotent; controleer aantal en checksum/totaal van `views` en aantallen vóór en na; verwijder exact de verwerkte rijen **alleen na** succesvolle verificatie en commit. Bij fout: volledige rollback van de archiveringstransactie, niet gedeeltelijk wissen.
- Herhaald starten zonder nieuwe records mag geen extra totalen optellen. Houd rekening met races met binnenkomende requests en SQL-indexen; archiveer uitsluitend oude afgesloten dagen.
- De huidige `DELETE FROM visitor_stats_daily` adminfunctie moet voor volledige einde-campagneverwijdering ook aggregaattabellen meenemen, na afzonderlijke bevestiging. Test dat geen kavels, accounts, afbeeldingen of snapshots worden geraakt.
- Incidentcontrole: mislukte migratie/archivering mag de bestaande website niet onderbreken. Back-up/integriteitsherstelplan verplicht voor databaseveranderingen.

## Noodzakelijkheid en belangenafweging

- Noodzaak *tijdelijke* codes: onderscheiden van herhaalde bezoeken op dezelfde UTC-dag en veilige samenstelling van dagtotalen en, indien gewenst, anonieme overlaptellingen per kavelpaar.
- Noodzaak *lange* codes: onvoldoende aangetoond; archiveren verkort juist de bewaartermijn van codes.
- Noodzaak *lange* anonieme totalen: vergelijken van kavelinteresse gedurende volledige campagne en eindevaluatie van de liefdadigheidsveiling. Bevestig dat aggregaten met kleine cellen niet alsnog indirect identificeerbaar zijn; pas eventueel drempels toe in rapportage.
- Mogelijke rechtsgrond art. 6(1)(f) AVG: vereist nog een organisatorisch geaccordeerde, feitelijk onderbouwde positieve noodzakelijkheid- en belangenafweging. Ga niet automatisch uit van vrijstelling van toestemming voor alle vormen van tracking.
- Transparantie: kort publieksbericht met rechtspersoon/contact, technisch doel, rechtsgrond, beide bewaartermijnen, bezwaar-/rechteninformatie, OVH-hosting. Geen 'goedgekeurd' claim zonder review.

## Werkwijze voor veilige uitvoering

1. Deze ontwerp-PR bespreken en expliciet bevestigen dat een **nieuwe database-aggregatiestructuur** toegestaan is; en welke overlapstatistieken vereist zijn.
2. In afzonderlijke PR exact schema/migratie, handmatige archiveerflow, aangepaste dashboardqueries, privacytekst en regressietests implementeren; **GEEN automatische opschoning**.
3. Offline/testdatabase: idempotentie, gelijktijdige bezoeken, aantallen, verwerkingsfouten, rechten, volledige verwijdering, snapshots en terugval naar oude rapportage verifiëren.
4. Afzonderlijke risico-review voor MySQL-wijzigingen; databaseaanpassing pas na afzonderlijke expliciete autorisatie en veilige backup.
5. Nieuwe releaseallowlist en workflow via handmatig goedgekeurde PR, omdat bestaande zesbestanden-code-only workflow **geen** database/schema-migratie dekt. Alle CI op exacte SHA, nieuw offline plan, hostkey/root/drift.
6. Pas na positieve privacybeoordeling, concrete releasegoedkeuring en afzonderlijke definitieve deployautorisatie eventuele productie-uitrol.

**Besluit:** dit ontwerp is een veiliger pad, geen garantie op AVG-conformiteit en geen toestemming om huidige privacy-releasegates te passeren.
