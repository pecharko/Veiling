# Productievoorbereiding verkoperspercentage

Herstel van snapshots van vóór de migratie is geen vereiste. De nieuwe snapshotcode weigert een herstel als een kavelrij geen geldig verkoperspercentage bevat. Bestaande ZIP-bestanden blijven bewaard. Bewaar voor herstel naar het tijdstip vóór de migratie een afzonderlijke volledige databaseback-up en fotokopie.

## Releasescope en baselines

- De gedeelde OVH-release `kavel-verkoperspercentage-20261128` bevat zes admin- en PHP-bestanden. De verwachte hashes zijn gebaseerd op de per-bestand-readback van [het adminoverzicht](https://github.com/pecharko/Veiling/actions/runs/44276802499), [admin-CSS](https://github.com/pecharko/Veiling/actions/runs/44631253527), [de kaveleditor en het formulier](https://github.com/pecharko/Veiling/actions/runs/44821242176) en [de verkoperbestanden](https://github.com/pecharko/Veiling/actions/runs/46123075382).
- `private/snapshots.php` vereist een afzonderlijk geautoriseerde uitrol via de bestaande beschermde [eenbestandsworkflow](https://github.com/pecharko/Veiling/actions/workflows/snapshot-photos-onefile-release.yml). De bronhash is `cec508bbb4cf9340206aad87f0ff320dd945a9dd6b8439a78078a7fc41fafdbd`. De OVH-baseline `5ade15588c6f463a8601662f1bb92aa2ca89cb43cf0a8d67b110d9eb09943be4` komt uit [de beschermde readback](https://github.com/pecharko/Veiling/actions/runs/45019240173) en is bevestigd via [de read-only run op de huidige main](https://github.com/pecharko/Veiling/actions/runs/46122927724).
- De enige SQL-migratie is `database/migrations/20261127_lot_seller_percentage.sql` met SHA-256 `724b412ad3e7b5a17e935464c4290b78d1c390fc012bda83ced8efb14846fcca`. Eén `ALTER TABLE lots` voegt `seller_percentage` toe als `TINYINT UNSIGNED NOT NULL DEFAULT 0` en beperkt de waarden tot 0, 30 en 60 met een checkconstraint.

## Uitvoervolgorde na autorisatie

1. Controleer op exact actuele main de bronhashes en geslaagde push-CI. Controleer in de productiedatabase read-only of `lots` op InnoDB staat, de kolom en constraint afwezig zijn en de server de constraint kan afdwingen. Verifieer een volledige databaseback-up met schema en data en een fotokopie van hetzelfde herstelpunt. Plan de overgang zonder gelijktijdige catalogus- of snapshotwrites.
2. Rol uitsluitend `private/snapshots.php` uit via de beschermde route. Controleer de productie-baseline via gepinde SFTP, de codeback-up, het rollbackartefact en de readback. Houd snapshotbewerkingen gepauzeerd tot het schema is bijgewerkt.
3. Voer na afzonderlijke SQL-autorisatie de gemergede migratie één keer uit. Controleer het kolomtype, de default, de actieve checkconstraint en dat bestaande kavels op 0% staan. Stop bij drift of een onduidelijke uitkomst en stel de toestand read-only vast.
4. Rol na afzonderlijke productieautorisatie de zes bestanden uit het gedeelde manifest uit. Verifieer per bestand de driftcheck, codeback-up, bronhash en readback. Controleer functioneel de schuifregelaar, waarschuwing en filter. Verifieer een vers snapshot na de migratie. Een hersteltest gebeurt uitsluitend op een geïsoleerde bestemming.

## Rollback

De zes adminbestanden kunnen met hun codeback-up teruggezet worden terwijl de additieve kolom en de nieuwe snapshotcode actief blijven. Vóór de SQL-migratie kan de oude snapshotcode nog worden teruggezet met haar afzonderlijke rollbackartefact. Daarna vereist volledig herstel naar het oude schema een afzonderlijk goedgekeurd herstel van de database en foto's naar hetzelfde back-uppunt, gevolgd door herstel van de oude snapshotcode. Breng eerst eventuele wijzigingen sinds de back-up in kaart. Bij een onduidelijke write-uitkomst is de eerste stap uitsluitend read-only reconciliatie.
