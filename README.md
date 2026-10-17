# Beheer veilingcatalogus 41 Lokeren

Deze repository bevat de **broncode en documentatie** van de online veilingcatalogus van 41 Lokeren: <https://41lokeren.be>.

**Status (17 oktober 2026):** de repository is aangemaakt en de documentatiebasis wordt ingericht. **De huidige productiebroncode is nog NIET geïmporteerd.** GitHub is dus momenteel nog geen volledige broncodeback-up van de actieve website.

## Belangrijkste documenten

- [Functionele requirements](docs/REQUIREMENTS.md) — levend register met acceptatiecriteria en verificatiestatus.
- [Technische architectuur](docs/ARCHITECTURE.md) — huidige opzet en openstaande verificatiepunten.
- [Uitrol en herstel](docs/DEPLOYMENT.md) — veilige werkwijze bij wijzigingen op OVHcloud.
- [Testplan](docs/TESTPLAN.md) — minimale regressiecontroles.
- [Wijzigingslogboek](CHANGELOG.md) — wijzigingen aan code en documentatie.

## Geplande mapstructuur

```text
catalogus-public/     # Publieke PHP-site, assets en admin (nog importeren)
private/              # PHP-backend, ZONDER secrets of productieback-ups
docs/                 # Functionele en technische documentatie
.github/              # Afspraken voor wijzigingen
```

## Lokale kopie op je Mac

1. Installeer [GitHub Desktop](https://desktop.github.com/) en log in.
2. Kies **File > Clone repository** en selecteer `pecharko/Veiling`. Kies bijvoorbeeld `~/Documents/Projecten/Veiling` als lokale locatie.
3. GitHub Desktop synchroniseert de huidige repository naar je Mac. **De OVHcloud-productiecode zit daar nog niet in.**
4. Maak via RemoteDrive een aparte, beveiligde kopie van de volledige actuele website op je Mac. Dit is vooralsnog de **productiebron**.
5. Controleer die kopie op secrets, persoonsgegevens en back-updata **voordat** je broncode in de GitHub-map kopieert en commit.
6. Upload of push nooit `private/config.php`, `uploads/`, `private/backups/`, database-exporten of wachtwoorden.

**Let op:** `.gitignore` helpt om bestanden uit te sluiten, maar voorkomt niet dat iemand secrets handmatig toevoegt en verwijdert geen historische commits.

## Afspraken per wijziging

Iedere wijziging aan de applicatie koppelen we aan een requirement-ID en werken we gelijktijdig bij in:
1. broncode;
2. `docs/REQUIREMENTS.md` (inclusief status en acceptatiecriteria);
3. `CHANGELOG.md`;
4. testresultaten en zo nodig `docs/DEPLOYMENT.md`.

Wijzigingen op GitHub komen **niet automatisch** op OVHcloud terecht: uitrol gebeurt voorlopig gecontroleerd via RemoteDrive. Pas na verificatie van de werkelijke broncode kan de software als geteste baseline worden gemarkeerd.

## Back-ups

De Git-repository is geen vervanging voor back-ups van database, uploads, productieconfiguratie en snapshots. Bewaar deze apart, beveiligd en met een getest herstelproces.
