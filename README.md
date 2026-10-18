# Beheer veilingcatalogus 41 Lokeren

Deze repository bevat de **broncode en documentatie** van de online veilingcatalogus van 41 Lokeren: <https://41lokeren.be>.

**Status (17 oktober 2026):** de actuele applicatiebroncode is door de gebruiker vanaf een OVHcloud-kopie naar deze repository gepusht en belangrijke PHP-bestanden zijn statisch geïnspecteerd. **Nog geen geteste/volledige herstelbaseline:** database, kavelfoto's, configuratiegeheimen en sommige ontbrekende assets zijn niet in Git opgeslagen. Zie [eerste code-audit](docs/CODE_AUDIT_2026-10-17.md).

## Belangrijkste documenten

- [Functionele requirements](docs/REQUIREMENTS.md) — levend register met acceptatiecriteria en verificatiestatus.
- [Technische architectuur](docs/ARCHITECTURE.md) — huidige opzet en openstaande verificatiepunten.
- [Databaseschema](docs/DATABASE.md) — **alle vijf tabellen geverifieerd**: `lots`, `lot_images`, `settings`, `admins` en `login_attempts`.
- [Uitrol en herstel](docs/DEPLOYMENT.md) — veilige werkwijze bij wijzigingen op OVHcloud.
- [Testplan](docs/TESTPLAN.md) — minimale regressiecontroles.
- [Wijzigingslogboek](CHANGELOG.md) — wijzigingen aan code en documentatie.
- [Code-audit 17/10/2026](docs/CODE_AUDIT_2026-10-17.md) — statisch vastgestelde functionaliteit, risico's en opvolging.

## Huidige mapstructuur

```text
catalogus-public/     # Publieke PHP-site, assets en admin
private/              # PHP-backend, ZONDER secrets of productieback-ups
docs/                 # Functionele en technische documentatie
.github/              # Afspraken voor wijzigingen
```

## Lokale kopie op je Mac

1. Installeer [GitHub Desktop](https://desktop.github.com/) en log in.
2. De bestaande lokale clone is aangemaakt onder `/Users/chrisdemol/Code/GitHub/Veiling`.
3. Gebruik in GitHub Desktop **Fetch origin** / **Pull origin** om de nieuwste broncode én documentatie van GitHub op je Mac te synchroniseren.
4. De aparte beveiligde kopie van OVHcloud staat onder `/Users/chrisdemol/Code/41Lokeren-Productie` en valt buiten Git.
5. Controleer elke nieuwe broncodewijziging op secrets, persoonsgegevens en back-updata **voordat** je commit/pusht.
6. Upload of push nooit `private/config.php`, `uploads/`, `private/backups/`, database-exporten of wachtwoorden.

**Let op:** `.gitignore` helpt om bestanden uit te sluiten, maar voorkomt niet dat iemand secrets handmatig toevoegt en verwijdert geen historische commits.

## Afspraken per wijziging

Iedere wijziging aan de applicatie koppelen we aan een requirement-ID en werken we gelijktijdig bij in:
1. broncode;
2. `docs/REQUIREMENTS.md` (inclusief status en acceptatiecriteria);
3. `CHANGELOG.md`;
4. testresultaten en zo nodig `docs/DEPLOYMENT.md`.

Wijzigingen op GitHub komen **niet automatisch** op OVHcloud terecht: uitrol gebeurt voorlopig gecontroleerd via RemoteDrive. De geïmporteerde code is **statisch** beoordeeld; pas na uitvoering van het testplan mag een release als geteste baseline worden gemarkeerd.

## Back-ups

De Git-repository is geen vervanging voor back-ups van database, uploads, productieconfiguratie en snapshots. Bewaar deze apart, beveiligd en met een getest herstelproces.

## Op te volgen verbeterpunten

- [GitHub-issue #2](https://github.com/pecharko/Veiling/issues/2) — consistent en veilig snapshot-/herstelproces.
- [GitHub-issue #3](https://github.com/pecharko/Veiling/issues/3) — unieke lotnummers bevestigd; functionele tests en resterende databaseschema's opvolgen.
- [GitHub-issue #4](https://github.com/pecharko/Veiling/issues/4) — ontbrekende assets en installatieverwijzingen controleren.
