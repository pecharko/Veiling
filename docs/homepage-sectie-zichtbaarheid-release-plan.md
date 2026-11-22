# Productievoorbereiding homepageschakelaars

PR #664 staat op broncommit `7975689a1f7327462f407856474b6e6732b4194a`. De productie-uitrol en de databasemigratie wachten op afzonderlijke exacte autorisaties. Voor deze release is herstel van snapshots van vóór de migratie geen acceptatievoorwaarde. De gemergede compatibiliteitscode blijft behouden. Bestaande archieven blijven bewaard.

## Code en OVH-baselines

- `releases/homepage-sectie-zichtbaarheid-20261122.json` bevat de beheerpagina en de publieke homepage voor de bestaande gedeelde OVH-route. De baselines zijn de geauthenticeerde readbacks van [Veilinginfo](https://github.com/pecharko/Veiling/actions/runs/43905976934) en [de homepage](https://github.com/pecharko/Veiling/actions/runs/45013385873).
- `releases/homepage-sectie-zichtbaarheid-snapshot-20261122.json` is het kandidaatmanifest voor uitsluitend `private/snapshots.php`. De baseline komt uit de [laatste beschermde uitrol](https://github.com/pecharko/Veiling/actions/runs/44820278547). De gedeelde OVH-route weigert dit pad. De bestaande beschermde workflows zijn vastgezet op hun eigen release-ID. Deze kandidaat heeft een apart te beoordelen beschermde workflow nodig.
- Valideer bij uitvoering de exacte actuele main-SHA en de bronhashes. Controleer per route de actuele OVH-hashes via gepinde SFTP vóór enige upload. Bij drift stopt de uitrol. Maak per route een back-up van de originele code, valideer de SHA-256 daarvan en vergelijk de readback na upload met de bronhash. Bewaar het rollbackartefact.

## Databasevoorbereiding

De enige SQL-kandidaat is `database/migrations/20261122_homepage_recruitment_visibility.sql` met SHA-256 `d1a39e6994bba6d2d702aaaea63a38b7cfde1e038a8851f1718b8d264bd9f27d`. Eén `ALTER TABLE settings` voegt `show_artist_recruitment` en `show_artwork_recruitment` toe. Beide zijn `TINYINT(1) NOT NULL DEFAULT 1`. Bestaande instellingen blijven behouden.

Vóór de geplande wijziging controleert de eigenaar in de productieomgeving read-only dat de verbinding de juiste database gebruikt, `settings` op InnoDB staat, rij `id=1` bestaat en beide nieuwe kolommen afwezig zijn. Bij een gedeeltelijk of onverwacht schema stopt de voorbereiding. Maak bij de latere uitvoering direct vooraf een volledige databaseback-up met schema en data en een bijbehorende fotoback-up. Controleer de back-up en bewijs dat die op een geïsoleerde bestemming kan worden teruggezet. Bewaar productiegegevens uitsluitend in de daarvoor bestemde beveiligde opslag. Een catalogussnapshot alleen is geen volledige databaseback-up, omdat authenticatietabellen daarin ontbreken.

## Uitvoervolgorde na autorisatie

1. Verifieer de verse database- en fotoback-up. Plan een wijzigingsvenster zonder gelijktijdige catalogus- of snapshotwrites.
2. Rol eerst de beschermde snapshotcode uit en verifieer de readback. De bestaande snapshotcode exporteert de volledige instellingenrij en zou na de SQL-wijziging de nieuwe kolommen bij verificatie afwijzen.
3. Voer na afzonderlijke SQL-autorisatie precies het gemergede migratiebestand eenmaal uit. Verifieer daarna beide kolommen, hun type, `NOT NULL`, standaardwaarde `1`, beide waarden `1` in rij `id=1` en het behoud van de bestaande instellingen. Bij een onduidelijke uitkomst uitsluitend read-only reconciliatie.
4. Laat een nieuw snapshot na de migratie verifiëren. De geïsoleerde MySQL-test op PR #664 bevestigt de standaardwaarden en het terugzetten van beide vlaggen. Voer een eventuele hersteltest uitsluitend geïsoleerd uit.
5. Rol na aparte toestemming de twee PHP-bestanden uit. Verifieer de readback en laat de eigenaar beide zichtbaarheidsschakelaars functioneel accepteren.

## Rollback

Bij problemen met alleen de schakelaars kan de eerdere PHP-code worden teruggezet terwijl de additieve kolommen en de nieuwe snapshotcode behouden blijven. De vorige PHP-code leest en schrijft de nieuwe kolommen niet. Als de nieuwe kolommen nog bestaan, moet de nieuwe snapshotcode actief blijven. Een volledige rollback vereist afzonderlijke toestemming, een beoordeeld herstel van database en foto's naar hetzelfde back-uppunt en pas daarna herstel van de vorige snapshotcode. Controleer eerst welke productiegegevens na het back-uppunt zijn gewijzigd. Bij een ambigue write wordt de servertoestand eerst read-only vastgesteld en volgt geen automatische herhaling of schemawijziging.
