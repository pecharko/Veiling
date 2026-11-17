# OVH MX Plan — ongelezen Mail-teller (preview)

## Functionele scope
- Bestaande menu-link heet **Mail ↗** en opent `https://webmail.mail.ovh.ca/` in een nieuw tabblad.
- Een badge met het **aantal ongelezen berichten in INBOX** verschijnt alleen voor een ingelogde Administrator of Veilingmeester. Een tijdelijke rolwissel naar Assistent verbergt link en badge.
- Browser controleert bij het openen van een beheerpagina, na terugkeer naar het tabblad en maximaal elke 120 seconden zolang zichtbaar.
- Geen numerieke badge bij 0. Bij ontbrekende configuratie verschijnt een neutrale status **Niet gekoppeld**, bij fout/timeout een neutrale **?**. Geen van beide is een werkelijk aantal ongelezen berichten. Geen afzenders, onderwerpen, berichten of previews.

## Beveiliging
- `admin/mail-ongelezen.php`: alleen GET, bestaande sessie- en accountcontrole, effectieve-roltoets (ook na rolwissel), JSON zonder mailinhoud, no-store en noindex.
- `private/mail_unread.php`: uitsluitend toegestane, vooraf geverifieerde OVH-host (`imap.mail.ovh.net`, `ssl0.ovh.net` of `imap.mail.ovh.ca`) op poort 993, geverifieerde TLS-certificaten, beperkte time-outs, uitsluitend IMAP LOGIN/STATUS INBOX (UNSEEN)/LOGOUT. Er wordt geen bericht geopend of gemarkeerd als gelezen.
- Mailboxwachtwoord komt **nooit** in GitHub, JavaScript, HTML, een URL, logbestand of de respons. Geen automatische OVH-webmailaanmelding.
- Een MX Plan-wachtwoord heeft meer rechten dan deze teller nodig heeft. Diefstal ervan kan toegang tot de volledige mailbox geven. Beveiliging van de serverconfiguratie en individuele beheerdersaccounts is daarom essentieel.

## Voorwaardelijke configuratie (niet activeren zonder afzonderlijke productie- en secretsautorisatie)
De private `config.php` wordt buiten de repository beheerd. Een bevoegde beheerder kan daar later een optionele sleutel instellen:

```php
'mail_unread' => [
  'enabled' => true,
  'user' => 'veiling@41lokeren.be',
  'host' => '<GEVERIFIEERDE_OVH_IMAP_HOST>',
  'password' => '<UITSLUITEND OP DE SERVER INVOEREN>',
],
```

Bovenstaand voorbeeld bevat **geen echt wachtwoord**. Zonder geldige sleutel is de teller standaard uitgeschakeld, terwijl de Mail-link blijft werken. De server moet uitgaand TLS-verkeer naar poort 993 toestaan. Geen PHP IMAP-extensie nodig.

## Open verificatie vóór productie
1. PHP-lint en offline regressies `tests/admin_role_menu_contract_test.php` en `tests/admin_mail_unread_contract_test.php` op schone preview.
2. HTTP- en browsertests voor Administrator, Veilingmeester, Assistent en Veilingmeester die naar Assistent wisselt.
3. Gedrag bij 0, 1, 99, meer dan 99 ongelezen berichten en bij fout/timeout; visuele controle desktop en mobiel.
4. Serveromgeving, regionale IMAP-host en TLS/IMAP-uitgaand verkeer testen zonder credentials te loggen.
5. Afzonderlijke beveiligde configuratieprocedure en concrete rollback vastleggen; aparte exacte merge- en productieautorisatie.

Geen live mailboxverbinding, secretswijziging, workflowdispatch of productieactie is onderdeel van deze previewontwikkeling.

## Browseracceptatie: bekende fout na release 20261117

De productie-upload van `mail-unread-20261117` is technisch geverifieerd, maar de functionele acceptatie is **FAIL** omdat de ongelezen teller niet verschijnt. De productieconfiguratie is bewust niet geactiveerd; de backend leverde daarom HTTP 503, waarna de client elke 120 seconden stil het badge-element verborg. Dit is reproduceerbaar vanuit het broncodecontract, zonder echte mailbox.

De previewcorrectie maakt het onderscheid zichtbaar: `200 {"available":false,"reason":"not_configured"}` zonder IMAP-verbinding, neutraal `Niet gekoppeld` en geen periodieke retry totdat een nieuwe pagina wordt geopend. Bij een geconfigureerde maar defecte verbinding volgt HTTP 503 en neutraal `?`; bij succesvol IMAP-resultaat toont de badge een echt aantal of niets bij nul. Het effectieve rollenmodel blijft ongewijzigd. Echte ongelezen aantallen blijven afhankelijk van apart geautoriseerde, niet in Git opgeslagen OVH-credentials en de bevestigde regionale host.

**Releaseadministratie blijft open:** `functional_acceptance=FAIL`, `independent_ovh_readback=PENDING`. Geen automatische rollback, productiecorrectie of secretwijziging.
