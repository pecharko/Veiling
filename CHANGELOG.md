# Wijzigingslogboek

Dit logboek volgt de wijzigingen in de GitHub-repository. Historische applicatieversies hieronder zijn **overgenomen uit eerdere projectbesprekingen**; zonder import van productiebroncode zijn ze **niet onafhankelijk geverifieerd**.

## [Unreleased]

### Toegevoegd — 2026-10-17
- Private GitHub-repository ingericht als toekomstige broncode- en documentatiebasis.
- Beveiligende `.gitignore` voor productiedata, geheime configuratie, snapshots en uploads.
- Functionele requirementsbaseline in `docs/REQUIREMENTS.md`.
- Documentatie voor architectuur, lokale kopie, deploy- en herstelproces, en testplan.

### Nog te doen
- Actuele OVHcloud-productiecode veilig importeren, zonder credentials, database-inhoud, uploads of snapshots.
- Vereisten en releasehistoriek toetsen aan de daadwerkelijke code.
- Back-up-/herstelmodule in een testomgeving valideren.

## Historisch (gerapporteerd, nog te verifiëren)

- **v1.5.1** — Menukoppeling 'Back-up & herstel' in adminomgeving.
- **v1.5** — Opslagdashboard, snapshotgroottes en meldingen bij beperkte ruimte.
- **v1.4** — Back-up & herstel van database en foto's.
- **v1.3.1** — Verbeteringen aan het kavelbeheerformulier.
- Eerdere versies: initiële veilingcatalogus, admin, CSV- en fotobeheer.

Bewaar daadwerkelijke deploymentdata en commit-SHA's vanaf de eerste geïmporteerde productiebaseline.
