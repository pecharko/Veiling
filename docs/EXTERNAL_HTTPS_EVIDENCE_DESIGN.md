# Externe HTTPS-bewijsbron voor 41lokeren.be

Status: **ontwerp / read-only**, geen wijziging van een bestaande deploymentgate.

## Bekend probleem
De OVH Application Firewall in Hosting Configuration blokkeert GitHub-hosted runners met HTTP 403 (runs 40327048692 en 40326235254). Dat is geen bewijs van downtime voor gewone bezoekers. Runner-403 wordt nooit als HTTP-200-succes geteld. Laat OVH Application Firewall ingeschakeld.

## Voorkeursrichting: externe uptime-monitor
UptimeRobot v3 ondersteunt externe HTTP-monitors en een REST API met read-only toegang. Bron: https://uptimerobot.com/api/v3/ en https://uptimerobot.com/blog/introducing-the-uptimerobot-v3-api/

1. Maak afzonderlijke **HTTP monitors** voor `https://41lokeren.be/` en `https://41lokeren.be/admin/login.php` die elk status **200** verwachten. Verifieer eerst dat het provider-netwerk niet door OVH WAF wordt geweigerd; meerdere meetlocaties genieten voorkeur.
2. Maak een API credential met **uitsluitend leesrechten** en bewaar die, na gebruikersgoedkeuring, in GitHub Actions Secrets. Nooit een sleutel in Git, issue, chat, output of artifact plaatsen.
3. Bouw een afzonderlijke read-only GitHub job die het externe monitorresultaat ophaalt en fail-closed valideert op exacte monitor-ID, doel-URL, status `UP`, laatste meettijd, recencyvenster, afwezigheid van actieve incidenten en een geldige providerrespons. Verwar een laatst bekende `UP` nooit met een gegarandeerd verse meting; leg provider-specifieke velden vast na inspectie van een gesaneerd voorbeeld.
4. Koppel het evidence-artifact aan **exacte release SHA** en fase (`pre` of `post`). Vermijd replay, verlopen evidence, onbewezen timestamps, verwisseling van monitors en gedeeltelijke dekking.
5. Behoud naast dit HTTP-bewijs alle andere gates: CI, allowlist, pinned OVH SFTP-hostkey, SHA-256 baseline, drift, privé codeback-up, rollback, readback en aanvullende gebruikersacceptatie van fotoherstel.
6. Maak pas **na succesvolle onafhankelijke proefrun** een afzonderlijke security-reviewed PR om de huidige runner-HTTP-gate te vervangen door het externe bewijs. Die PR mag niet automatisch mergen. Productie-deploy vereist dan nieuwe expliciete autorisatie voor de nieuwe exacte SHA/allowlist.

## Fail closed / risico's
- Ontbrekende credentials, API-fout, rate limit (429), ontbrekende monitor, onjuiste URL, onjuiste status, stale of onvolledige meetgegevens: **stop**.
- Externe monitorstatus bewijst niet dat alle fotobestanden aanwezig zijn of dat snapshot-restore werkt. Dat vraagt afzonderlijke functionele acceptatie.
- Het automatisch loslaten van de HTTP-runnergate op grond van alleen een algemene groene uptimebadge is verboden.
- Alle GitHub checks zijn vanuit het runner-netwerk; de aparte monitor is een onafhankelijke meetbron, maar de beschikbaarheid en toelaatbaarheid daarvan moeten eerst in OVH bevestigd worden door een geslaagde meting.

## Volgende activeringsafhankelijkheid
De gebruiker moet de externe monitoren in een provider-account activeren of een al bestaande monitorbron aanwijzen. Zonder die bron en een read-only API credential kan het ontwerp niet als werkende automatische verificatie worden beschouwd.
