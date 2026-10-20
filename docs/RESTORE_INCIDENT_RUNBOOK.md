# Incidentprocedure bij een onderbroken herstel — 41 Lokeren

**Status:** voorbereid als onderdeel van fase 3A van [issue #2](https://github.com/pecharko/Veiling/issues/2), nog niet uitgerold op productie. **Dit is een veilige triagehandleiding en géén opdracht om automatisch te herstellen.** Een geteste, idempotente hersteltool wordt in een latere fase ontworpen.

## Herkenningssignalen

De PHP-website en de administratie tonen **HTTP 503 / De catalogus is tijdelijk niet beschikbaar** wanneer in de privéback-upmap een actief of beschadigd hersteljournal (`private/backups/.restore-active.json`), een achtergebleven journaaltempbestand (`.restore-active.tmp-*`) of in `catalogus-public/` een vorige fotomap (`.uploads-before-restore-*`) bestaat.

Een proces kan na het verplaatsen van foto's zijn gestopt **vóór** of **ná** een MySQL-commit. Een opgeslagen fase `photos_active` bewijst niet welke van beide is gebeurd. Daarom wordt het systeem niet vanzelf opnieuw vrijgegeven. Directe statische foto-URLs en caches worden door de PHP-gate niet volledig afgesloten.

## Eerste handelingen — geen herstelmutaties

1. **Geen nieuw herstel, geen snapshot verwijderen, geen kavel- of fotowijzigingen.** Neem de publiek zichtbare onderhoudsmelding niet weg en klik niet opnieuw op `HERSTEL`.
2. Noteer datum, tijd, betrokken snapshotnaam, waargenomen fout en eventuele serverlogs. Gebruik alleen het beheerders- of hostingaccount waarvoor je gemachtigd bent.
3. Maak via beveiligde SFTP of hostingtools **alleen kopieën** van het journal, de volledige actieve map `uploads/` (indien aanwezig), eventuele `.uploads-before-restore-*` en `.restore-*` mappen, en de betrokken ZIP's onder `private/backups/`. Bewaar hun oorspronkelijke bestandsnamen en hashes. Verwijder of hernoem niets op de hosting.
4. Maak een afzonderlijke, read-only database-export via phpMyAdmin of een goedgekeurde hostingback-upfunctie en bewaar die buiten de hosting. Snapshots bevatten ook accounts en wachtwoordhashes: behandel downloads als vertrouwelijke persoonsgegevens.
5. Laat een deskundige de MySQL-toestand en fotoverwijzingen onafhankelijk vergelijken met (a) het doelarchief én (b) de veiligheidskopie van vlak vóór de restore. Controleer bestandspaden, SHA-256 van foto's en de benodigde adminaccounts. De journal-`snapshot_sha256` helpt de juiste ZIP te identificeren.

## Veilige technische diagnose (fase 3B, nog niet live)

De module is geïsoleerd getest: [Snapshot-CI-run #39326595129](https://github.com/pecharko/Veiling/actions/runs/39326595129) bevat T-055 met tien veiligheids- en integriteitscontroles, aangevuld met T-050B/T-054. De code is **nog niet uitgerold op productie**.

Een bevoegde technische operator kan **uitsluitend op een beveiligde offline kopie** van de code, MySQL-database, journal en fotomappen het diagnostische plan uitvoeren:

```sh
php scripts/restore_diagnose.php --plan
```

Dit commando verwijdert of hernoemt niets, schrijft geen SQL-rijen en wist nooit een journal. Het resultaat bevat onder meer `database_matches_target`, `active_photos_match_target`, `archive_photo_hashes_verified` en een conservatieve status. Mogelijke uitkomsten: `target_verified_pending_review` (alle MySQL-rijen en actieve foto's gelijk aan het doelarchief, **maar nog geen hersteltoestemming**), `mixed_or_unknown_keep_maintenance` (afwijkingen), `blocked_unverified` (ontbrekend/onveilig bewijs) of `clear` (geen herstelmarker gevonden; **geen** volledige data-integriteitscontrole). Iedere situatie met een marker blijft in onderhoud totdat het hele incidentprotocol en de verantwoordelijke akkoord zijn.

Bewaar de complete bewijsbestanden vóór diagnose. De tool vertrouwt niet op de journalfase als bewijs van SQL-commit, controleert de ZIP-hash opnieuw na lezing en geeft geen persoonsgegevens uit de database terug. Hij biedt bewust **geen** `--restore`, `--clear` of `--rollback`. De huidige OVHcloud-hosting hoeft geen PHP CLI te ondersteunen om deze diagnose veilig uit te voeren op een onafhankelijke kopie.

## Alleen bij exact volledig herstelde doeltoestand: menselijke afronding (fase 3C, nog niet live)

De nieuwe tool `scripts/restore_finalize.php` heeft **bewust slechts twee functies**: een alleen-lezen `--plan` en een apart, expliciet geautoriseerd `--finalize`. Beiden zijn alleen via PHP CLI mogelijk. De operator dient vóór een beslissing een onafhankelijke externe kopie te controleren van de veiligheids-ZIP, het herstelarchief en de oorspronkelijke foto's.

Voor een bevoegde operator, op een **beveiligde geïsoleerde kopie** van de testomgeving:

```sh
php scripts/restore_finalize.php --plan --safety-snapshot <naam-van-veiligheidszip> --operator <operatorcode>
```

Het JSON-rapport toont of de database én foto's van de actieve site gelijk zijn aan de gekozen doelsnapshot en of de originele fotomap bij de afzonderlijke veiligheids-ZIP hoort. Alleen de status `verified_target_requires_explicit_approval` geeft een *mogelijke* finale vervolgstap aan — **geen automatische toestemming**. De operator moet een afzonderlijk autorisatieproces volgen en daarna een exacte, aan het plan gebonden `run_id` en `approval_challenge` doorgeven, met de toestemmingszin, de verklaring van gecontroleerde externe back-up en de SHA-256 die de operator **op die externe veiligheids-ZIP** heeft berekend (op macOS bijvoorbeeld `shasum -a 256 /pad/naar/veiligheidskopie.zip`). De CLI vergelijkt die SHA-256 met de geverifieerde safety-ZIP op de server. De tool accepteert niet zomaar een bevestiging zonder beide waarden. De volledige CLI-syntaxis wordt door de helpmelding getoond; deze runbook geeft opzettelijk geen uitvoerbaar productiecommando.

Na geldige finale toestemming verifieert de tool alles opnieuw. Hij verplaatst oude fotobestanden **zonder verwijderen** naar `private/backups/.restore-preserved-old-<run-id>`, maakt een privéauditbewijs `private/backups/.restore-finalized-<run-id>.json` en verwijdert het actieve hersteljournal als allerlaatste stap. Bij een nieuwe onderbreking vóór die laatste stap blijft onderhoud actief en is een nieuw plan nodig. Een werkelijk gemengde, onduidelijke of ontbrekende toestand **blijft geblokkeerd**; hiervoor is nog geen veilige automatische herstelfunctie.

**Op productie niet uitvoeren** voordat deze fase apart is gereviewd, op de hosting is gevalideerd, de code formeel is uitgerold én er uitdrukkelijke incidentautorisatie is gegeven. De huidige runtime op 41lokeren.be bevat deze nieuwe tool nog niet.

## Alleen bij bevestigde pre-commit-crash: originele foto's gecontroleerd terugplaatsen (fase 3D; nog niet live)

Een SIGKILL **na de fotoswap, maar vóór de databasecommit**, kan databasegegevens van vóór het herstel laten staan terwijl de actieve foto's al naar de doelsnapshot zijn overgeschakeld. Hiervoor is een nauw beperkt extra pad ontworpen. Een bevoegde operator kan uitsluitend op een beveiligde **wegwerp-/incidentkopie** een read-only voorstel inspecteren:

```sh
php scripts/restore_precommit.php --plan --safety-snapshot <naam-van-veiligheidszip> --operator <operatorcode>
```

De uitkomst `original_database_and_photos_verified` betekent alleen dat **alle vijf tabellen** van de actuele database identiek zijn aan de veiligheidssnapshot, alle oude fotobytes en alle doelfotobytes correct bij hun eigen ZIP passen en de maptoestand ondubbelzinnig is. Een latere `--rollback` vergt **nieuwe, afzonderlijke toestemming**, exacte run-ID en bijbehorende SHA-256-challenge, een ingestemde Nederlandse bevestigingszin én de afzonderlijk berekende SHA-256 van de *offsite* safety-ZIP. De tool kan geen SQL terugzetten: hij bewaart de doelfoto's privé, plaatst de oude fotomap terug, controleert de integriteit opnieuw en heft onderhoud pas als allerlaatste stap op. De drie tussentoestanden zijn herhaalbaar **na nieuw onderzoek en nieuwe autorisatie**, zonder fotobytes te verwijderen.

**Nooit uitvoeren op de live hosting als experiment.** Als database, oudere foto's, doelarchief of externe kopie niet volledig kunnen worden aangetoond, blijft HTTP 503 actief. Een gemengde DB-commit of ontbrekend origineel fotoarchief vereist een aparte, door een deskundige goedgekeurde reconstructie. Deze fase is in review en nog niet op productie uitgerold. De drie herstel-/herstartgrenzen, checksumcontrole en handmatige goedkeuringsketen zijn **geïsoleerd getest met 22 geslaagde T-057-controles** in [Snapshot-CI-run #39374712561](https://github.com/pecharko/Veiling/actions/runs/39374712561).

## Bij onbekende of gemengde databasetoestand: dubbele referentievergelijking (fase 3E, nog niet live)

Een database kan door bijkomende storingen, externe schrijvers of beschadiging noch exact overeenkomen met de veiligheidskopie, noch met de doelsnapshot. **Geen van de bestaande herstelcommando's mag dan worden uitgevoerd.** Een nieuwe, strikt alleen-lezen diagnose is op een geïsoleerde kopie mogelijk:

```sh
php scripts/restore_mixed_diagnose.php --plan --safety-snapshot <naam-van-veiligheidszip>
```

Ze vergelijkt alle vijf tabellen in hetzelfde consistente MySQL-leesmoment met **beide** archieven. Het rapport toont slechts per tabel `both`, `safety_only`, `target_only` of `neither`, samen met niet-gevoelige bestandshashindicaties. De tool controleert apart actieve, voorheen actieve en privé bewaarde fotosets en vraagt nogmaals database- en archiefchecks op om tussentijdse wijzigingen niet stilzwijgend te accepteren.

Een resultaat `database_not_equal_to_either_archive` is **geen** opdracht om rijen uit beide ZIP's te combineren: admins, kavels en fotorelaties kunnen dan ongeldig worden. Ook `safety_state_matches_but_keep_maintenance` of `target_state_matches_but_keep_maintenance` heft de blokkering **niet** op; de specifieke bestaande herstelpaden vereisen nog steeds afzonderlijke toestemming, bewijs en gecontroleerde externe kopieën. Onjuist of onvoldoende bewijs geeft `blocked_unverified`. De diagnose heeft **geen** commando om bestanden te verplaatsen, SQL te wijzigen of de journalmarker te verwijderen.

Deze read-only module is alleen met fictieve gegevens getest: **16 geslaagde T-058-controles** in [Snapshot-CI-run #39376629673](https://github.com/pecharko/Veiling/actions/runs/39376629673). Deze stap is niet op OVHcloud beschikbaar totdat een afzonderlijke code-release expliciet geautoriseerd is. Voor echt gemengde data blijft alleen deskundig onderzoek op een afgesloten kopie toegestaan.

## Mogelijke toestanden en beslismomenten

| Geobserveerd na controle | Potentieel passend herstelpad | Belangrijke voorwaarde |
| --- | --- | --- |
| DB nog origineel, `uploads/` ook origineel | Alleen markertoestand oplossen | Beide datasets onafhankelijk geverifieerd; geen ander probleem |
| DB nog origineel, `uploads/` ontbreekt of bevat nieuwe foto's, oude fotomap is compleet | Vorige fotomap veilig terugplaatsen | Oude DB+oude foto's moeten samen aantoonbaar volledig zijn |
| DB is al de doelsnapshot, nieuwe uploads voldoen aan alle ZIP-hashes | Succesvol herstel administratief afronden | Oude map niet verwijderen vóór veilige externe kopie |
| MySQL bevat gemengde/onbekende gegevens, fotoverwijzingen ontbreken, journal is corrupt of bewijs onvolledig | Systeem in onderhoud laten, gecontroleerde reconstructie op aparte testomgeving | **Geen gok-rollback** en geen online herstel onder druk |

**Cruciaal:** `database_committed` in het journal is niet voldoende om foto's te verwijderen; hetzelfde geldt voor een oudere fase als argument om de database terug te rollen. Controleer altijd de werkelijke database en beschikbare foto-inhoud. Een processtop kan precies tussen twee journalupdatestappen gebeuren.

## Vrijgeven van de website

De onderhoudsindicator mag pas worden verwijderd nadat de gegevens en foto's een **bewezen overeenkomstige toestand** vormen, een volledige externe veiligheidskopie bestaat en de herstelbeslissing is gedocumenteerd en uitdrukkelijk door de verantwoordelijke is goedgekeurd. Daarna: read-only HTTP-controles, beheerdersaanmelding, kaveloverzicht, fotokoppelingen, unieke lotnummers en integriteit van de downloadbare snapshot-ZIP controleren.

**Doe niet:** het journal verwijderen om HTTP 503 weg te krijgen, foto's over de actieve map heen kopiëren zonder hashvergelijking, `restore` nogmaals starten, of een SQL-dump importeren zonder afzonderlijk incidentplan. Deze handleiding bevat bewust geen ongeteste productie-`mv`-, `rm`- of SQL-writecommando's.

**Fase-3C-verificatie:** de geïsoleerde [Snapshot-CI-run #39372914703](https://github.com/pecharko/Veiling/actions/runs/39372914703) bevestigt 25 tests voor goedkeuring, offsite-SHA-vergelijking, crash tijdens private evidencepreservering, veilige hervatting met nieuwe challenge en weigering van herhaald vrijgeven. De test is uitgevoerd zonder enige live productiegegevens.

## Wat is in CI aantoonbaar getest?

- **T-050A:** consistente MySQL-snapshot bij gelijktijdige update.
- **T-051:** synchronisatie van de deelnemende adminwrite-routes via MySQL-lock.
- **T-050B / T-052:** SIGKILL **na fotoswap, vóór commit**; PHP 503 blijft actief; alleen fictieve CI-gegevens zijn manueel teruggezet.
- **T-053:** afgehandelde SQL-fout vóór commit; DB en foto's automatisch teruggezet, journal opgeruimd.
- **T-054:** SIGKILL **ná commit, vóór fase-update**; journalfase is ambigu. De test controleert de doel-DB en alle fotohashes onafhankelijk vóór opruimen, uitsluitend in de wegwerptestomgeving.

**Niet bewezen:** recovery na willekeurige host-, storage- of netwerkcrashes, directory-`fsync` op OVHcloud, racevoorwaarden bij externe MySQL/SFTP-writes en automatische/gebruikersvriendelijke beheerder-recovery. Zie [onderzoeksdocument](SNAPSHOT_CONSISTENCY_CRASH_STUDY_2026-10-19.md) en het openstaande [issue #2](https://github.com/pecharko/Veiling/issues/2).
