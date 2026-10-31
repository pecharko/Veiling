# Parallel Workflows

Deze referentie bevat bestaande instructies en casussen, zonder nieuwe autorisatie. Volg eerst SKILL.md en het actuele repositorycontract.

## Parallelle PRs en releases: conflictbewuste voortgang

- Neem aan dat meerdere gebruikersgesprekken, PRs en releases op `pecharko/Veiling` **bewust parallel** gebeuren. Een gewijzigde `main`-SHA is normaal en **op zichzelf geen conflict**. Vergelijk nooit uitsluitend twee historische SHAs om een probleem te concluderen.
- Controleer bij elke nieuwe beurt actuele `main`, PR-heads, CI, exact gewijzigde bestandspaden, release-ID, manifestbronhashes en OVH-baselines; gebruik voor conclusies alleen actuele GitHub- en release-evidence. Vergelijk afhankelijkheden (database, snapshots, workflows, rechten/secrets) naast pad-overlap.
- **Geen overlap of andere relevante afhankelijkheid?** Ga automatisch verder met toegestane read-only voorbereiding en documentatie/PR-merges, zonder herhaalde `go`-vragen. Een nieuwe `main` vereist wel een nieuwe exacte autorisatie-SHA voor de nog uit te voeren productieactie.
- **Overlap of onverwachte remote drift?** Blokkeer alleen de getroffen releaseactie; identificeer exact bestand, betrokken PR/commit, oude/nieuwe SHA en noodzakelijke hercontrole of rebase. Laat andere onafhankelijke werkzaamheden doorgaan. Rebase of manifestvernieuwing mag geen bestaande productietoestemming stilzwijgend hergebruiken.
- Bij onzekerheid over een mogelijk herschreven `main`: controleer echte commit-ancestry voordat je force-push of fout beweert; een andere HEAD door parallel werk is niet verdacht.
- Bewaar de fail-closed productiegrenzen: upload, verwijdering, SQL-migratie, secrets/rechten en security-gatewijzigingen vragen elk de bestaande specifieke autorisatie, immutable actuele commit, SHA-256-baselines en rollback/verify waar van toepassing. `go` is geen productietoestemming.
- Meld de gebruiker **alleen concrete conflicten of noodzakelijke beslissingen**, met het minimale bewijs. Geen valse alarmen over normale parallelle voortgang. Probeer niet te claimen dat er continu in de achtergrond wordt gemonitord; controleer bij interactie of via een werkelijk ingestelde automation.


