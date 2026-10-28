# Admin wachtwoordreset — afzonderlijke installatie- en acceptatiestappen

Scope: admin login + twee aparte openbare, noindex resetformulieren; helper in `private/`.
Afzender: `noreply@41lokeren.be`. De implementatie gebruikt PHP `mail()` met vaste From-header en mailt uitsluitend actieve accounts.

**Niet automatisch activeren.** Voor een productiedeployment:
1. Bevestig via OVH dat `noreply@41lokeren.be` echt als afzender kan verzenden (domein/DNS SPF, DKIM, DMARC en OVH-mailconfiguratie). Test aflevering inclusief spamfolder. `mail()` retourneert niet noodzakelijk aflevergarantie. Als OVH een geauthenticeerde SMTP-relay vereist, implementeer die apart met credentials buiten Git.
2. Controleer read-only het productieschema en maak een data-back-up. Vraag afzonderlijke expliciete autorisatie voor `database/admin_password_reset_migration.sql`; voer de migratie uit vóór PHP-deployment. Migreer niet automatisch via HTTP.
3. Deploy uitsluitend de goedgekeurde runtimebestanden met een nieuw manifest, read-only remote SHA, driftgate en 7-daags rollbackartifact. Database-rollback is afzonderlijk.
4. Test end-to-end met een actief testaccount: verzoek, ontvangen e-mailafzender, geldige 30-minutenlink, eenmalig gebruik, wachtwoordwissel, invalidatie van bestaande sessie, ongeldig/verlopen token, limieten en niet-bestaand account. Verifieer dat een gedeactiveerd account nooit een resetmail krijgt.
5. Evalueer of productie mailverzending veilig beschikbaar is; totdat dit bewezen is, geen functionele acceptatie.

Security: generieke respons (geen accountdetectie), CSRF, SHA-256 tokenhashes, maximaal 30 minuten, éénmalig token, IP+e-mail rate limiting, noindex/no-referrer, geen persoonsgegevens of links in logs. De tabel bevat requestmetadata, en moet worden meegenomen in snapshot/restore-beleid voordat de functie live gaat.
