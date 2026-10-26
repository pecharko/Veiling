# Manuele publieke HTTPS-acceptatie — 41 Lokeren

**Beslissing gebruiker (26 oktober 2026):** verificatie dat website en admin *UP* zijn gebeurt manueel door de gebruiker. Geen bijkomende automatische UptimeRobot-freshness-integratie vereist. UptimeRobot kan als aanvullende observatie blijven bestaan.

## Waarom
OVH Application Firewall blokkeert HTTP-verzoeken vanuit GitHub-hosted Actions runners met 403, ook terwijl bezoekers de website kunnen openen. Een runner-403 is geen geslaagde HTTP-test, noch bewijs van downtime.

## Beoogde releaseprocedure (nog niet technisch geactiveerd)
1. **Vooraf:** gebruiker bezoekt vanuit gewone browser zowel `https://41lokeren.be/` als `https://41lokeren.be/admin/login.php`; beide moeten zichtbaar correct laden. Leg een expliciete bevestiging vast per release (niet hergebruiken van eerdere controles), met tijdstip en exact release-SHA.
2. **Technische gates in GitHub:** require green CI, exact geautoriseerde SHA en runtimeallowlist, SFTP-hostkey pinning, remote SHA-256-baseline, driftcontrole vlak voor upload, privé codeback-up met 7 dagen artifactbewaring, per-file upload readback en best-effort rollback bij uploadfouten.
3. **Na upload:** gebruiker controleert dezelfde twee URL's vanuit gewone browser en bevestigt afzonderlijk dat ze correct laden. Bij problemen: markeer release NIET als geverifieerd, activeer herstelplan en onderzoek rollback. Bij uitblijven van bevestiging blijft de release 'uitgerold, niet functioneel geverifieerd'.
4. **Indien gebruiker niet beschikbaar:** geen onbevestigde uitrol als 'geverifieerd' markeren; houd release in onbesliste status.
5. De GitHub-runner HTTP-403 blijft gedocumenteerd als **niet toetsbaar vanuit runner**. Vervang niet door een stilzwijgend groen resultaat of `continue-on-error`.
6. De huidige bestaande deploymentworkflow heeft nog een blokkerende HTTP-200 gate; die mag enkel in een **aparte security-reviewed PR** vervangen worden door een expliciet handmatig pre-deploy approval-veld en post-deploy acceptance-procedure. Die PR niet automatisch mergen. Een aangepaste productiedeployment vereist nieuwe exacte SHA-/allowlist-/baseline-autorisatie.

## Minimaal handmatig bewijs (in release issue/PR)
```
RELEASE HTTPS PRE-CHECK: SHA=<approved_sha>; website=OK; admin-login=OK; checked_at=<local date/time>
RELEASE HTTPS POST-CHECK: SHA=<approved_sha>; website=OK; admin-login=OK; checked_at=<local date/time>
```
Dit is door de gebruiker gerapporteerd bewijs, geen automatisch gemeten HTTP-status.
