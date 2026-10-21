# Concept AVG-belangenafweging — bezoekersstatistieken 41 Lokeren

**Datum:** 21 oktober 2026  
**Status:** CONCEPT / NIET GOEDGEKEURD — geen toestemming om tracking op productie te activeren.  
**Verantwoordelijke (opgave organisator):** 41 Lokeren — 41clublokeren@gmail.com. Controleer nog of dit de juiste juridische entiteit is.  
**Gekozen uiterste bewaareinddatum:** 31 oktober 2027 (inclusief); handmatige verwijdering vanaf **1 november 2027**. Geen automatische opschoning gewenst.  
**Reikwijdte:** `visitor_stats_daily` en de privacytekst, niet de afzonderlijke OVH-webserverlogs.  
**Code onderzocht:** `private/visitor_stats.php`, `catalogus-public/admin/statistieken.php` en `catalogus-public/privacy.php` op `main` SHA `ec88b6ba6f6da3ad5d970883d758d87a038f4410`.

## 1. Voorgestelde rechtsgrond en doeltoets

Beoogde rechtsgrond is artikel 6(1)(f) AVG, **gerechtvaardigd belang**; dit is nog niet formeel aanvaard. Het concrete doel is inzicht krijgen in het gebruik van de online charity-veilingcatalogus: dagelijkse aantallen bezoekersdagen en paginaweergaven en welke kavels aandacht krijgen, om de organisatie en communicatie over de lopende veiling bij te sturen. Geen advertentietracking, profilering, biedingen of externe analytics beoogd.

**Voorlopige toets:** het belang is concreet en in beginsel legitiem, op voorwaarde dat 41 Lokeren dit effectief gebruikt voor de veiling en het geen ruimer commercieel doel wordt.

### Aanvullend organisatorisch doel: evaluatie na afloop van de veiling

41 Lokeren wil na afloop van de liefdadigheidsveiling van 2027 onderbouwen welke kavels in welke campagneperiodes belangstelling kregen, zodat de vereniging de presentatie, communicatie en selectie van kavels bij toekomstige liefdadigheidsveilingen kan verbeteren. Daarbij wil zij per kavel en periode zowel paginaweergaven als unieke **bezoekersdagen** kunnen analyseren. Dit laat bijvoorbeeld toe om te onderscheiden tussen veel herhaalde raadplegingen binnen één dag en een bredere dagelijkse belangstelling, en om inzichten uit de gehele campagne opnieuw te berekenen bij de eindevaluatie.

De daggebonden HMAC-identifier kan functioneel worden gebruikt om binnen één UTC-dag herhaalde raadplegingen te onderscheiden. Hij maakt **geen** betrouwbare herkenning van dezelfde persoon over verschillende dagen mogelijk. De huidige adminweergave toont bovendien slechts 30 dagen; een rapport over de volledige campagne vereist een afzonderlijke analyse of functionele uitbreiding. Er is geen bewijs dat een dergelijke volledige eindevaluatie momenteel is geïmplementeerd of daadwerkelijk uitgevoerd.

**Noodzakelijkheidstoets:** deze organisatorische motivering is plausibel maar bewijst nog niet dat alle pseudonieme dagcodes tot en met 31 oktober 2027 moeten blijven bestaan. Geaggregeerde aantallen per dag en kavel kunnen de meeste eindevaluaties ondersteunen. Voor de gekozen langere bewaring moet 41 Lokeren vooraf schriftelijk vaststellen welke concrete herberekeningen of kwaliteitscontroles de afzonderlijke dagcodes vereisen, waarom een eenmalige dagelijkse aggregatie niet volstaat, en wie de evaluatie uitvoert. Zonder die aanvullende onderbouwing blijft het noodzakelijkheidsoordeel negatief en de privacyvrijgave geblokkeerd.

## 2. Werkelijke gegevensverwerking

Bij GET-verzoeken zonder Do Not Track, voor niet-herkende bots en met een geconfigureerd `analytics_secret`, wordt tijdelijk IP-adres, user-agent en UTC-dag verwerkt tot een HMAC-SHA256 identificator. De tabel bewaart per dag de pseudonieme bezoekercode, paginatype (`home` of `lot`), optioneel de interne kavel-ID en teller `views`. Het ruwe IP-adres en de browserstring worden niet in deze tabel opgeslagen. De HMAC-identificator is **gepseudonimiseerd en kan nog een persoonsgegeven zijn**. De instelling Do Not Track wordt gerespecteerd voor deze module. De adminpagina is afgeschermd met login en biedt handmatig wissen met CSRF-controle, preview en bevestiging.

**Belangrijke beperking:** de huidige adminrapporten raadplegen enkel de **laatste 30 UTC-dagen**. Een terugkerende bezoeker telt op verschillende dagen opnieuw mee; de getoonde unieke bezoekers zijn dus bezoekersdagen, geen unieke personen over de volledige veiling. Het script biedt op dit moment geen seizoensrapportage die individuele dagelijkse identifiers tot 31 oktober 2027 nodig maakt.

## 3. Noodzakelijkheid en alternatieven

- Voor een ruwe telling van pagina- en kavelweergaven zijn langdurig bewaarde bezoekerscodes **niet noodzakelijk**; geaggregeerde dagtotalen zijn minder ingrijpend.
- Voor de huidige telling van unieke bezoekersdagen kan tijdelijk een per dag wisselende pseudonieme code nuttig zijn. De daadwerkelijke noodzaak om **alle** dergelijke codes tot **31 oktober 2027** te bewaren, is **niet aangetoond**. Het huidige dashboard toont slechts 30 dagen.
- Minder ingrijpende, bruikbare alternatieven: dag- en kaveltotalen bewaren en detailcodes na een kortere onderbouwde periode verwijderen; of vooraf volledig anonieme geaggregeerde tellingen. Die alternatieven zijn technisch/functioneel nog niet uitgewerkt en werden door de organisator vooralsnog niet gekozen.
- Om de gekozen bewaartermijn van alle gegevens toch te kunnen verdedigen, moet de organisator een **specifiek extra doel** vastleggen waarvoor de individuele dagcodes gedurende de hele campagne nodig zijn, en aantonen waarom aggregatie of kortere bewaring niet even doeltreffend is. Alleen de wens alle gegevens te behouden, is onvoldoende.
- **Oordeel noodzakelijkheid voor volledige bewaring:** momenteel **NIET AANGETOOND**.

## 4. Belangenafweging bezoekers

**Factoren die risico beperken:** geen derde analytics-aanbieder; geen trackingcookies voor deze module; daggebonden HMAC-identificator; geen ruwe IP/user-agent in statistiekentabel; DNT-respect; afgeschermde admin; handmatige verwijdering beschikbaar; geen gedragsprofiel over opeenvolgende dagen bedoeld.

**Resterende belangen/risico's:** onzichtbare verwerking op een publieke site, mogelijke bezoekende minderjarigen, pseudonieme code kan per dag wel bezoeken aan kavels verbinden, eventuele gevoeligheid van kavelvoorkeuren, data-inbreuk, onzekerheid over correcte toegang en logging op de hosting, en lange bewaartermijn zonder aangetoonde noodzaak. De traceerbaarheid wordt niet nul door HMAC; geen uitspraken over volledige anonimiteit.

**Voorlopige balans:** beperkte interne statistiek kan verdedigbaar zijn onder passende waarborgen. **Bewaring van alle detailcodes tot 31 oktober 2027 is op dit moment disproportioneel/onvoldoende onderbouwd** zolang het noodzakelijkheidsonderzoek niet positief wordt afgerond. Daarom is de AVG-vrijgave **NIET GOEDGEKEURD**.

## 5. Operationele waarborgen en verplichtingen

1. Organisator legt de **precieze juridische identiteit** van de verantwoordelijke vast en bevestigt dat `41clublokeren@gmail.com` aanvragen en bezwaren ontvangt.
2. Maak vooraf een korte, duidelijke publieke privacyverklaring met de **daadwerkelijk vastgestelde** rechtsgrond, expliciete datum `31 oktober 2027`, betrokken rechten (inzage, bezwaar bij art. 6(1)(f), verwijdering wanneer van toepassing, klacht bij GBA) en contact. Verwijder interne concepttekst als 'moet juridisch beoordelen' pas na echte goedkeuring; geen onware complianceclaims.
3. Leg de toegang tot de admin vast, controleer datalek-/inzageprocedures, controleer hostingcontract en verwerkersverplichtingen afzonderlijk. Log geen identificatoren in foutmeldingen.
4. Zonder automatische opschoning: wijs een verantwoordelijke aan om **op 1 november 2027** handmatig **alle** rijen uit `visitor_stats_daily` te verwijderen via de bestaande bevestigde adminfunctie; verifieer dat de teller op nul staat; archiveer uitsluitend bewijs van uitvoering zonder bezoekerdata. Als de statistieken daarna actief blijven, ontstaan nieuwe rijen: stop eerst de registratie of herhaal verwijderen en verifieer de uitschakeling. Een einddatum in een tekst verwijdert niets automatisch.
5. De gekozen datum is een **uiterste doeltermijn**, geen automatisch bewijs dat elk record tot die dag noodzakelijk is. Documenteer vóór activering waarom vroege bezoeken nog nodig zijn in oktober 2027. Zo niet: kies een kortere termijnaanpak voor de detailcodes.
6. Publiceer of activeer statistieken niet met de status 'privacy approved' zolang dit document geen positief ondertekend oordeel bevat.

## 6. Open punten voor formele goedkeuring

- **[BLOKKEREND]** Concreet noodzakelijk gebruik van daggebonden bezoekerscodes voor de volledige campagne: welk doel, welke analyses, waarom niet met aggregaten of een kortere termijn?
- **[BLOKKEREND]** Finaliseer belangenafweging na dit bewijs, met expliciete motivatie waarom bezoekersrechten niet zwaarder wegen.
- **[OPEN]** Juridische identiteit verwerkingsverantwoordelijke en interne eigenaar van de verwijdering.
- **[OPEN]** Definitieve korte privacyverklaring met datum, rechtsgrond en bezwaar-/rechteninformatie.
- **[OPEN]** Vaststellen hoe bezoekersmeting op/na einddatum uitgeschakeld wordt (zonder automatische opschoning, zoals gevraagd).

**Besluit — 21 oktober 2026:** een legitiem statistisch doel is aannemelijk, maar de noodzakelijkheid en proportionaliteit van alle detaildata tot 31 oktober 2027 zijn nog niet aangetoond. **Geen privacygoedkeuring en geen toestemming voor productiedeployment.**

## Bronnen

- Belgische Gegevensbeschermingsautoriteit, 'Gerechtvaardigd belang': https://www.gegevensbeschermingsautoriteit.be/professioneel/avg/rechtsgronden/gerechtvaardigd-belang
- GBA, 18 oktober 2024: https://gegevensbeschermingsautoriteit.be/burger/nieuws/2024/10/18/gegevensverwerking-voor-gerechtvaardigde-belangen-de-edpb-publiceert-zijn-richtsnoeren
- EDPB, Guidelines 1/2024 (consultatieversie): https://www.edpb.europa.eu/public-consultations/guidelines-12024-on-processing-of-personal-data-based-on-article-61f-gdpr_en
