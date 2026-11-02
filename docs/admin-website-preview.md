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

Zodra preview is gestart verzamelt die PHP-beheersessie geen bezoekers- of
geostatistieken. Dit blijft na sluiten gelden tot uitloggen of sessieverloop,
ook voor andere tabbladen met dezelfde cookie. Andere browsers/sessies blijven
normaal tellen. Bestaande rollen administrator, assistant en veilingmeester
behouden toegang via het bestaande menu. Er is geen productie-SQL nodig.

Het releasemanifest `releases/admin-website-preview-20261102.json` bevat precies
vijf runtimebestanden, in volgorde stats-uitsluiting, bootstrap, index, detail,
privacy. Remote baselines komen uit de eenmalig geautoriseerde gepinde read-only
run 41399163638 op main 108bbc0f33e0a6d5b8bc10739277ac865ba99e72
(2026-11-02 06:19:16 UTC). Artifact 13120257356, ZIP SHA-256
`6f89ff0b913430288119b2f17c240ef47e4d2c640f4b4960ec6013829c62d062`.
Alle vijf live hashes kwamen op dat meetmoment overeen met die main. Vóór een
werkelijke upload blijven verse driftcontrole, back-up, rollback en readback
verplicht; deze voorbereiding autoriseert geen merge of deployment.

De Mac moet vóór de volgende release gecontroleerd het bestaande journal
upgraden en de oude succesvolle release afronden, zie
[Mac-controller](ovh-controller-macos-v1.md#opeenvolgende-releases-expliciete-afronding-journalversie-2).
Tests controleren echte HTTP/login/CSRF/sessies en statswrites in een geïsoleerde
MySQL-fixture, plus lokale journalmigratie/afronding en GitHub-evidencecontracten.
