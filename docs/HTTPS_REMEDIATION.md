# HTTPS-beveiliging: vastgestelde HTTP-login en herstelplan

**Project:** Veilingcatalogus 41 Lokeren  
**Datum:** 18 oktober 2026  
**Status (18 oktober 2026):** permanente HTTP→HTTPS-redirect voor login en homepage **op productie bevestigd** en bijbehorende `.htaccess` **onder Git opgenomen**. Overige adminroutes en technische cookiecontroles nog open.  
**Issue:** [#6 — dwing HTTP→HTTPS af](https://github.com/pecharko/Veiling/issues/6).

## Bevestigd bewijs

- Chrome toont bij een expliciet HTTPS geopende adminpagina **Connection is secure** en **Certificate is valid** voor `41lokeren.be`: TLS en het certificaat werken voor die verbinding.
- Op een Mac gaf `curl -I http://41lokeren.be/admin/login.php` **`HTTP/1.1 200 OK`**, zonder `Location:`-header. Er is dus **geen HTTP→HTTPS-redirect** voor die URL.
- Op HTTP reageert de server met een `Set-Cookie: catalog_admin=...; Secure; HttpOnly; SameSite=Lax`-header. De **Secure**-cookieoptie voorkomt **niet** dat een wachtwoord via een HTTP-formulier kan worden gepost.
- `catalogus-public/admin/login.php` gebruikt `<form method="post">` zonder absolute HTTPS-action. Een in HTTP geopende pagina kan dus een inlog-POST via HTTP doen.
- `private/bootstrap.php` stelt de admincookie in met `secure=true`. Dit is nuttige verdediging, **geen HTTPS-afdwinging**.

**Historie 18 oktober 2026 — eerst tijdelijke 302 geplaatst:** de gebruiker deelde een nieuwe `curl -I http://41lokeren.be/admin/login.php`-uitvoer met **`HTTP/1.1 302 Found`** en **`Location: https://41lokeren.be/admin/login.php`**. De tijdelijke HTTP→HTTPS-redirect is voor de loginroute **bevestigd als werkend**. De regels staan nu kennelijk op OVHcloud, maar het exacte actieve `.htaccess`-pad en de volledige inhoud zijn niet onafhankelijk geverifieerd. **Aanvullend bevestigd 18 oktober 2026:** de directe HTTPS-login antwoordt `HTTP/2 200`; de volledige loginredirectketen is `HTTP/1.1 302 Found` → `Location: https://41lokeren.be/admin/login.php` → `HTTP/2 200`, zonder redirectlus; de publieke homepage antwoordt op HTTP met `302 Found` en `Location: https://41lokeren.be/`. **Vervolgens uitgevoerd:** de definitieve 301 is hieronder apart met curl-bewijs bevestigd; sessie-/logoutcontrole blijft open. Voer logins uitsluitend via HTTPS uit.

## Herstelvoorstel voor OVHcloud

OVHcloud documenteert het gebruik van **Apache mod_rewrite** via een bestand `.htaccess` in de **documentroot** (voor dit project de map `catalogus-public/`). [Officiële OVH-handleiding](https://help.ovhcloud.com/csm/en-gb-web-hosting-htaccess-url-rewriting?id=kb_article_view&sysparm_article=KB0052853).

**Vóór wijziging:**
1. Controleer met RemoteDrive of `catalogus-public/.htaccess` **al bestaat**. Verborgen bestanden zichtbaar maken.
2. Maak een lokale kopie van ieder bestaand bestand. **Overschrijf geen andere Apache-regels.**
3. Controleer in OVH Manager of de documentroot van `41lokeren.be` daadwerkelijk op `catalogus-public/` staat.
4. Werk eerst met een **tijdelijke HTTP 302** zodat de regel gemakkelijk te testen is en niet agressief door browsers wordt gecachet.

Wanneer geen `.htaccess` aanwezig is, kan dit de *inhoud* van een nieuw `catalogus-public/.htaccess` zijn. Indien er wel al één is, voeg de regel bovenaan de bestaande rewrite-regels toe, zonder bestaande regels te verwijderen:

```apache
# 41 Lokeren — tijdelijke proef: forceer HTTP naar HTTPS
RewriteEngine On
RewriteCond %{SERVER_PORT} ^80$
RewriteRule ^(.*)$ https://41lokeren.be/$1 [R=302,L]
```

Dit volgt OVHclouds gedocumenteerde SERVER_PORT 80-rewriteaanpak, met vastgelegd HTTPS-hostname om ongewenste redirects op basis van een door de bezoeker opgegeven Host-header te vermijden. De querystring blijft bij een externe redirect zonder nieuwe querystring normaal behouden. **Vereist test**: een andere proxyconfiguratie kan het poortgedrag beïnvloeden. Bij een 500-fout of redirectlus onmiddellijk de wijziging terugdraaien.

**Na upload via RemoteDrive:**

```bash
curl -I http://41lokeren.be/admin/login.php
curl -I http://41lokeren.be/
curl -I https://41lokeren.be/admin/login.php
curl -IL --max-redirs 3 http://41lokeren.be/admin/login.php
```

- HTTP → **302** en `Location: https://41lokeren.be/...` (voorlopige test).
- HTTPS → normale reactie (**geen HTTPS→HTTPS-lus**).
- Publieke website en admin blijven werken.
- Bij onverwachte reactie: zet oude `.htaccess` terug en onderzoek de hostingconfiguratie.

Wanneer dit gevalideerd is, verander `R=302` naar `R=301` en hertest. Controleer pas later, indien zinvol, of HSTS veilig kan worden ingeschakeld.

**Let op:** noch GitHub noch deze documentatie wijzigen de live OVHcloud-instellingen automatisch. De Apache-configuratie moet nog uitdrukkelijk en gecontroleerd worden geïnstalleerd.

**Definitieve redirect geslaagd — 18 oktober 2026:** na de overstap van `R=302` naar `R=301` heeft de gebruiker tweemaal `curl -sSI` uitgevoerd: `http://41lokeren.be/admin/login.php` antwoordt met **`HTTP/1.1 301 Moved Permanently`** en `Location: https://41lokeren.be/admin/login.php`; `http://41lokeren.be/` geeft **`301`** met `Location: https://41lokeren.be/`. Eerder bevestigde controles: geldige TLS-verbinding in Chrome, direct HTTPS-login `HTTP/2 200`, geen redirectlus in de 302-test. **Vervolg:** uitloggen/sessie opnieuw testen, andere adminroutes en technisch cookiegedrag nagaan. De door de gebruiker gedeelde actuele documentroot-configuratie is inmiddels in Git opgenomen als [`catalogus-public/.htaccess`](../catalogus-public/.htaccess). Het bestandspad `catalogus-public/.htaccess` is gebaseerd op de door de gebruiker opgegeven documentroot en gedeelde inhoud; de commentaarregel in Git is verduidelijkt van 'tijdelijke test' naar 'permanente redirect'. De Apache-regels zelf zijn identiek.

## Na herstel

1. Bevestig T-040 met de curl-uitvoer en een browsercontrole.
2. Log uit van de huidige beheersessie via **HTTPS** en log opnieuw in nadat de redirect is bewezen.
3. Indien eerder via HTTP werd ingelogd: wijzig het wachtwoord nadat HTTPS vaststaat; overweeg sessievernieuwing.
4. Plaats geen `Set-Cookie`-waarden, tokens, wachtwoorden of IP-hashes in tickets, screenshots of chatlogs.
5. Registreer uitroldatum, nieuwe regels en resultaten in het [testplan](TESTPLAN.md), [requirements](REQUIREMENTS.md) en [changelog](../CHANGELOG.md).

## Afzonderlijk probleem: GitHub-authenticatie op de Mac

Een Git-prompt `Password for 'https://pecharko@github.com'` gevolgd door `Password authentication is not supported` betekent dat Git een **accountwachtwoord** probeerde te gebruiken waar een token of geautoriseerde credential helper nodig is. Dit staat los van de OVHcloud HTTP-fout. Gebruik bij voorkeur **GitHub Desktop** en meld via **GitHub Desktop > Settings > Accounts > Sign into GitHub.com** opnieuw aan met de browser. `Fetch origin` / `Pull origin` synchroniseert daarna je lokale repository. Voor Terminal-git kan `gh auth login` met browseraanmelding plus `gh auth setup-git` gebruikt worden, mits GitHub CLI geïnstalleerd. Deel nooit tokens of wachtwoorden.
