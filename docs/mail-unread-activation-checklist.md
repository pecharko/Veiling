# Mail-ongelezenteller: veilige activatie op OVH MX Plan

Status: **voorbereiding/preview, geen autorisatie voor configuratie- of productiewijzigingen**.

## Vastgestelde dienst
- Mailbox: `veiling@41lokeren.be` op OVH MX Plan.
- Webmail: `https://webmail.mail.ovh.ca/` (OWA).
- OVH-paneel toont een overgang van OWA naar Zimbra. Dit is niet voldoende bewijs dat de mailbox zelf al gemigreerd is.
- Officieel gedocumenteerde Canadese MX Plan IMAP-host: `imap.mail.ovh.ca`, SSL/TLS-poort `993`. Controleer deze host opnieuw bij de feitelijke activatie en na de migratie.
- De bestaande Mail-knop gebruikt de webmail-browsersessie; de server-side IMAP-teller gebruikt **afzonderlijke** mailboxauthenticatie.

## Veilige niet-geauthenticeerde verbindingstest (lokaal, niet op productie)
Controleer vanaf een eigen Mac eerst of TCP/TLS naar de OVH-host werkt, zonder een gebruikersnaam of wachtwoord te sturen:

```sh
openssl s_client -connect imap.mail.ovh.ca:993 -servername imap.mail.ovh.ca -verify_return_error -brief </dev/null
```

Interpretatie: een geslaagde TLS-handshake bevestigt alleen de externe bereikbaarheid *vanaf die Mac*, **niet** vanaf de OVH PHP-hosting, en **niet** dat de mailbox aanmelding toestaat. Bij foutmelding geen TLS-verificatie uitschakelen. Niet testen met echte gebruikersgegevens in logs of gedeelde terminalopdrachten.

## Private config — uitsluitend na afzonderlijke exacte configuratieautorisatie
De al bestaande `private/config.php` bevindt zich buiten de publieke webroot, bevat mogelijk andere geheimen en mag nooit integraal worden gedeeld, vervangen of in Git opgenomen. Een geautoriseerde beheerder voegt uitsluitend het onderstaande optionele configuratie-element toe aan het bestaande array, zonder andere sleutels te wijzigen:

```php
'mail_unread' => [
  'enabled' => true,
  'user' => 'veiling@41lokeren.be',
  'host' => 'imap.mail.ovh.ca',
  'password' => '<ALLEEN_OP_DE_PRIVATE_SERVER>',
],
```

- Het **echte wachtwoord** wordt uitsluitend op de server toegevoegd en nooit in ChatGPT, GitHub, een workflowargument, broncode of logging gezet.
- Controleer vooraf dat `private/config.php` niet via de publieke URL kan worden gedownload; leg de configuratie alleen vast met persoonlijke bevoegdheid, veilige overdracht, beperkte bestandsrechten en een vooraf verifieerbare terugzetmogelijkheid.
- De secretwijziging is **niet** onderdeel van de gewone vijfbestands-release. Geen wijziging aan database, OVH-mailbox, wachtwoord, rollen, DNS, snapshots of workflows.
- Omdat het mailboxwachtwoord volledige mailboxrechten kan geven, is het risico groter dan de vereiste read-only teller. Beoordeel eerst of een afzonderlijke, bevoegdheidsbeperkte credential door OVH wordt ondersteund; ga daar niet zonder OVH-bewijs van uit.

## Acceptatie vooraf met fictieve gegevens
Op een geïsoleerde previewomgeving, zonder OVH-verbinding:
1. Ontbrekende/uitgeschakelde configuratie: het afgeschermde JSON-endpoint retourneert `200 {"available":false,"reason":"not_configured"}`; de Mail-knop blijft werken en het menu toont een neutrale `Niet gekoppeld`-badge zonder periodieke netwerkherpogingen tijdens dezelfde pagina.
2. Verkeerde/ontbrekende host of lege secret: zelfde veilige niet-gekoppelde toestand; geen socketverbinding.
3. Actieve maar onbereikbare IMAP-host: `503 {"available":false}` en neutrale `?`; geen fictief cijfer en geen wachtwoord of serverfout in het antwoord.
4. Gesimuleerde IMAP-status: 0 verbergt de badge, 1 toont 1, 99 toont 99, 100 toont 99+.
5. Alleen Administrator en Veilingmeester zien de knop; Assistent en tijdelijke rolwisseling naar Assistent zien de knop noch het endpoint. De bestaande autorisatie wordt op iedere API-aanvraag gecontroleerd.
6. Desk-/mobiele browseracceptatie, PHP lint en gerichte regressies zijn verplicht voordat een correctierelease wordt voorbereid. Een statische codecheck is geen echte browser-/IMAP-acceptatie.

## Na afzonderlijke activering
- Alleen vanaf de daadwerkelijke OVH-hosting een strikt begrensde TLS/IMAP-verificatie uitvoeren, met afgeschermde foutinformatie en zonder ruwe mailboxoutput.
- Na ontvangst van een werkelijk ongelezen aantal de functionele gebruikersacceptatie afzonderlijk bevestigen.
- Controleer de regionale host opnieuw na de aangekondigde OVH-migratie naar Zimbra.
- Houd `mail-unread-20261117` op `functional_acceptance=FAIL`, `independent_ovh_readback=PENDING`, `release_administration=OPEN` totdat per controle nieuw bewijs bestaat.

Geen productionele wijziging is door dit checklist-document geautoriseerd.
