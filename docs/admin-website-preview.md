# Website bekijken vanuit beheer

`Bekijk website` in het beheermenu opent na een geauthenticeerde POST met CSRF
in een nieuw tabblad de adminvoorbeeldweergave. Die omzeilt uitsluitend de
algemene instelling `Kavels tonen op website`. Alleen kavels met status published
of sold worden getoond; drafts blijven verborgen en website-offline blijft
gehandhaafd. De instelling zelf wordt niet gewijzigd.

Een gele ADMINVOORBEELD-balk bovenaan catalogus, kaveldetail en privacy vermeldt
de sessiemodus, met terugkeer naar beheer en een CSRF-beveiligde sluitknop.
Elke previewrequest controleert de bestaande actieve account, credentialproof en
rol opnieuw. Geen queryparameter kan de modus activeren. Responses zijn private,
no-store en noindex; publieke pagina's variëren op Cookie. Gewone bezoekers
krijgen hiervoor geen adminsessie.

Vanaf deze uitbreiding registreert uitsluitend een **geslaagde login** de combinatie
IP-adres/admin-ID. `Bekijk website` registreert of wijzigt geen IP-adres. Herhaalde
logins met dezelfde combinatie zijn een ongewijzigde no-op; een nieuw IP of een
andere admin achter hetzelfde IP krijgt een eigen rij. De uitsluiting kijkt naar
het IP-adres en geldt dus ook na uitloggen, in andere browsers en voor andere
gebruikers achter hetzelfde internetadres. IPv4-mapped IPv6 wordt als IPv4
behandeld; clientgestuurde forwardingheaders worden genegeerd.

Een afzonderlijke schakelaar in Statistieken staat standaard aan. Uitschakelen
laat bezoeken weer normaal tellen (ook in preview) wanneer de algemene
bezoekersregistratie aan staat. Alle geregistreerde IP/admin-combinaties blijven
bewaard, en geslaagde logins blijven de lijst aanvullen. Opnieuw inschakelen
hergebruikt de lijst. De banner vermeldt of het huidige IP daadwerkelijk wordt
uitgesloten. Bezoekers- én geostatistieken worden vóór HMAC/geo/INSERT overgeslagen.
De oude sessieflag is geen alternatieve uitsluitingsroute meer.

De banner blijft aan de bovenkant bij scrollen. De bestaande vaste navigatie
staat eronder, ook wanneer de banner op mobiel over meerdere regels loopt.

SQL-voorstel: `database/migrations/20261102_visitor_stats_ip_exclusions.sql`.
Twee aparte tabellen bevatten de IP/admin-lijst en de standaard ingeschakelde
schakelaar. Er is geen foreign key naar admins: herstel wist/vervangt accountrows,
maar mag de uitsluitingsgegevens niet wissen. Het opgeslagen admin-ID is de
registratie bij login; het wordt bij herstel niet opnieuw aan een accountnaam
gekoppeld. Beide tabellen vallen buiten de expliciete snapshotlijst; exports
bevatten ze niet en herstel bewaart de actuele lijst én schakelaar. Geïsoleerde
tests bewijzen dit met gegevens toegevoegd na het maken van de snapshot.

Eigenaarkeuze voor dit wijzigingspakket: `oude_snapshots_herstellen = nee`.
Er wordt geen nieuwe conversieroute voor oude snapshots toegevoegd en geen
bestaande backup verwijderd. Productie-SQL is niet uitgevoerd. Vóór uitrol is
aparte exacte SQL-autorisatie nodig; pas de beoordeelde migratie vooraf toe en
controleer beide tabellen/constraints en de singletonrij. Herhalen van de SQL
behoudt een eerder uitgezette schakelaar en alle adressen. Er is geen runtime-DDL.
Bij onbeschikbare opslag blijft login werken met een melding; analytics slaan
uit voorzorg over wanneer uitsluiting niet kan worden vastgesteld. SQL-tabellen
verwijderen als code-rollback is niet toegestaan: behoud deze nieuwe data.

Bestaande actieve sessies worden niet achteraf geregistreerd. Log na uitrol
opnieuw in om het huidige IP/admin-ID vast te leggen. De lijst kan de eerste
keer leeg zijn. De beheerpagina toont maximaal 50 recente combinaties; alle
opgeslagen adressen blijven gelden, ook als ze niet in die beperkte tabel staan.


De eerste, inmiddels geaccepteerde uitrol gebruikte
`releases/admin-website-preview-20261102.json` met precies
vijf runtimebestanden, in volgorde stats-uitsluiting, bootstrap, index, detail,
privacy. Remote baselines komen uit de eenmalig geautoriseerde gepinde read-only
run 41399163638 op main 108bbc0f33e0a6d5b8bc10739277ac865ba99e72
(2026-11-02 06:19:16 UTC). Artifact 13120257356, ZIP SHA-256
`6f89ff0b913430288119b2f17c240ef47e4d2c640f4b4960ec6013829c62d062`.
Alle vijf live hashes kwamen op dat meetmoment overeen met die main. Vóór een
werkelijke upload blijven verse driftcontrole, back-up, rollback en readback
verplicht; deze voorbereiding autoriseert geen merge of deployment.

De Mac gebruikt altijd hetzelfde journal. Vóór een volgende release moet de
laatst uitgevoerde release gecontroleerd worden afgerond; nooit resetten of
herhalen. Zie
[Mac-controller](ovh-controller-macos-v1.md#opeenvolgende-releases-expliciete-afronding-journalversie-2).
Tests controleren echte HTTP/login/CSRF/sessies en statswrites in een geïsoleerde
MySQL-fixture, plus lokale journalmigratie/afronding en GitHub-evidencecontracten.

De nieuwe login/IP-uitbreiding gebruikt zeven runtimebestanden: analytics_control,
visitor_stats, bootstrap, admin/login, index, admin/statistieken en privacy.
Het code-only manifest staat in dezelfde PR:
`releases/admin-login-ip-exclusions-20261102.json`.
De zeven echte remote baselines komen uit de afzonderlijk geautoriseerde
gepinde read-only run 41409711546 op main
`bafad0ec00e0720e4a846e98a7f54da74263ca89`, 2026-11-02 07:58:16 UTC.
Artifact 13124449275 heeft ZIP SHA-256
`00a4ed63f711dd1257b16610d44c93e2c9afb321d2cc1981187c813da8644db8`.
Alle zeven live hashes kwamen overeen met die main; de nieuwe source-hashes
worden samen met de code gecontroleerd. De volgorde is analytics-control,
tracker, bootstrap, login, index, statistieken en privacy. Het SQL-voorstel
wordt nooit in het uploadmanifest opgenomen en vereist afzonderlijke, exacte
productietoestemming vóór de code-uitrol.
