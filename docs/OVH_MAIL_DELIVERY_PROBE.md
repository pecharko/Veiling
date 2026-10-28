# Eenmalige OVH-mailtest (voorbereiding; niet uitgevoerd)

Afzender: `noreply@41lokeren.be`; ontvanger: `chris.pecharko@gmail.com` (toestemming verkregen).
`scripts/ovh_mail_delivery_probe.php` is **uitsluitend voor PHP CLI** en bevat geen publiek HTTP-endpoint, GitHub Actions workflow, dispatch of automatische verzending.

**Niet starten met deze PR.** Script is alleen bruikbaar wanneer een bevoegde operator op OVH een daadwerkelijke, niet-publieke PHP CLI kan uitvoeren, de projectstructuur aanwezig is (private/config.php buiten document root), en de aparte uitvoerings- en uploadautorisatie is verkregen. OVH Starter biedt mogelijk geen SSH/CLI; eerst read-only verifiëren. Zet dit script nooit in de openbare webroot en activeer geen cron.

Een lock/marker voorkomt herhaling na de eerste poging op dezelfde installatie. De marker wordt vóór `mail()` vastgelegd; na foutmelding geen automatische retry. Returncode 0 betekent enkel dat PHP de mail accepteerde, **niet** dat Gmail hem afleverde.

Indien OVH geen veilige CLI beschikbaar stelt, gebruik een apart te beoordelen afgeschermd uitvoeringsmechanisme; geen openbaar PHP-mailtestendpoint creëren. Voor daadwerkelijke activatie van de wachtwoordreset blijft `admin_password_resets`-migratie afzonderlijk geautoriseerd en zijn controle van afzender/SPF/DKIM/DMARC en ontvangen bericht nodig.
