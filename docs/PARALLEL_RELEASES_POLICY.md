# Parallelle ontwikkeling en releases — Veiling Deploy Manager

Meerdere gelijktijdige PR's, releases en chats zijn **normaal en toegestaan**. Een veranderende `main`-SHA is op zichzelf geen conflict en geen reden om alle andere werkzaamheden stil te leggen.

## Verplicht bij elke actie
1. Haal actuele `main`, PR-head, gevraagde immutable SHA, wijzigingen en workflowruns opnieuw op. Ga nooit af op alleen een historisch gesprek of een eerder vastgestelde `main`.
2. Vergelijk het **exacte padenset**, de betrokken remote bestands-SHA's, migraties en gedeelde uitvoeringsbronnen van de actieve release met interveniërende commits. Onderscheid disjuncte changes van inhoudelijke overlap.
3. Bij **geen overlap**: ga automatisch verder met read-only voorbereiding, PR-review en toegestane merges; herbereken alle releasegebonden exacte autorisatie-inputs en hergebruik nooit een oude `DEPLOY-...-<SHA>` na wijzigingen van `main`.
4. Bij **overlap** (zelfde runtimebestand, DB-migratie, deploymentworkflow, controller, secrets/rechten of afhankelijkheden), of onverwachte remote drift: stop uitsluitend de getroffen releaseactie, leg het concrete conflicterende pad/de SHA uit en werk een nieuwe baseline en evaluatie uit. Laat niet-gerelateerde trajecten doorgaan.
5. GitHub commits `main` die ouder lijken dan een eerder genoemde SHA: controleer bron en chronologie opnieuw. Concludeer pas force-push/rewrite of onveiligheid als GitHub-evidence dat daadwerkelijk aantoont; een andere HEAD is normaal bij parallel werk.
6. Blijf fail-closed op productieacties: voor elke upload, delete, migratie of wijziging van veiligheidsgrenzen gelden de bestaande immutable commit, bronhash, remote baseline en **afzonderlijke expliciete toestemming**. Een generieke `go` is geen productieautorisatie.
7. Bij langdurige parallelle reeksen: gebruik een release-identificatie en een korte conflictmatrix (`release_id`, PR, paden, basis-SHA, head-SHA, status, afhankelijkheden) als bewijs; verander deze alleen op basis van actuele connectorinformatie.
8. Meld de gebruiker **uitsluitend echte conflicten of vereiste beslissingen**. Vervang herhaalde `go`-vragen door autonome toegestane stappen. Beweer geen continue monitoring: controleer bij de volgende interactie, tenzij een echte automatisering is aangemaakt.

## Voorbeeld
Release A verandert alleen `catalogus-public/admin/login.php`, release B alleen `catalogus-public/admin/admin.css`. Nieuwe `main` door B blokkeert A niet; herbereken A's autorisatiecommit vóór upload. Als B ook `login.php` veranderde, blokkeer alleen A's upload totdat de inhoudelijke overlap en nieuwe remote baseline gecontroleerd zijn.
