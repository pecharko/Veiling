# Bezoekersstatistieken - veilige introductie

**Status:** ontwikkeld op featurebranch; NIET uitgerold. De MySQL-migratie raakt schema en vergt expliciete review.

## Werking
- Alleen GET op publieke homepage en gepubliceerde kavelpagina telt. Geen adminverkeer.
- Geen JavaScript, externe trackingprovider of trackingcookies. Bots via user-agent en DNT worden best-effort uitgesloten.
- Dagelijkse pseudonieme HMAC van datum, IP en user-agent; de geheime sleutel staat uitsluitend in private config en nooit in Git. Ruwe IP/user-agent worden niet in de statistiektabel bewaard.
- Een bezoeker wordt slechts binnen dezelfde UTC-dag herkend. **30-dagen unieke bezoekersdagen**, geen werkelijke unieke personen over 30 dagen: terugkerende personen tellen op nieuwe dagen opnieuw mee.
- De top-10-statistiek telt detailpaginaweergaven van kavels, niet unieke geïnteresseerden.
- De tabel bewaart maximaal ongeveer 30 dagen. Opschoning gebeurt steekproefsgewijs op 1% van geslaagde bezoeken: bij weinig verkeer kan data langer blijven staan. Voor gegarandeerde retentie is een apart geplande cleanup vereist.
- Gehashte bezoekersinformatie kan nog steeds persoonsgegevens zijn. Juridische/privacyreview en update van privacyverklaring zijn verplicht voor activatie; cookievrij betekent niet automatisch AVG-vrij.

## Afzonderlijke installatieprocedure
1. Laat CI, privacyreview en de bestaande restore/snapshot-compatibiliteit expliciet controleren. Een nieuwe tabel kan door vaste backup-tabellijsten overgeslagen worden.
2. Maak en verifieer vooraf een DATA-ONLY snapshot. Controleer de 250 MB MySQL-quota.
3. Voer `database/visitor_stats.sql` na expliciete autorisatie handmatig uit op OVH. Controleer naam en indexen.
4. Configureer in `private/config.php` een `analytics_secret` met minstens 32 cryptografisch willekeurige tekens. Deel deze nooit in chat of commits. Zonder sleutel registreert de app niets.
5. Leg de exacte runtime-allowlist vast: `private/visitor_stats.php`, `private/bootstrap.php`, `catalogus-public/index.php`, `catalogus-public/kavel.php`, `catalogus-public/admin/statistieken.php`. Alleen handmatig gestarte deployment na separate goedgekeurde plan en deploy.
6. Test eerst anonieme HTTPS-GET, adminauth, DNT/bot filtering, MySQL opslag, datumgroepering en top 10. Bestaande restore-integriteit en CSV/Foto-import 404 mogen niet regressief wijzigen.
7. Meet beperkte verkeersvolumes; voeg index/retentiebeleid toe als gebruik groeit. Maak geen live schattingen zonder echte data.

## Minimale acceptatietesten (nog te verifiëren)
- PHP lint alle gewijzigde PHP bestanden.
- Zonder analytics_secret geen SQL trackingquery.
- Ontbrekende tabel verbreekt de publieke pagina niet; admin meldt dat statistieken ontbreken.
- Herhaalde GET van dezelfde browser/dag verhoogt views zonder nieuwe dagbezoeker.
- Andere dag telt dezelfde browser opnieuw.
- Administratie vereist login.
- Migratie en restore onafhankelijk goedgekeurd en getest.
