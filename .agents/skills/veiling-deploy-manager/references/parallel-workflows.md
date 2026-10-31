# Parallel Workflows

Deze referentie bevat bestaande instructies en casussen, zonder nieuwe autorisatie. Volg eerst SKILL.md en het actuele repositorycontract.

## Parallelle ontwikkeling, seriële productie-rollout

- Vast gebruikersbesluit (31 oktober 2026): de gebruiker is de enige deployer en draait nooit twee deployments parallel. Behandel productie als één seriële keten en vraag deze aanname niet opnieuw. Meerdere ontwikkelgesprekken, PRs en CI-runs kunnen wel parallel lopen. Een gewijzigde `main`-SHA is op zichzelf geen inhoudelijk conflict; controleer de feitelijke diff en ancestry.
- Controleer bij elke nieuwe beurt actuele `main`, PR-heads, CI, exact gewijzigde bestandspaden, release-ID, manifestbronhashes en OVH-baselines; gebruik voor conclusies alleen actuele GitHub- en release-evidence. Vergelijk afhankelijkheden (database, snapshots, workflows, rechten/secrets) naast pad-overlap.
- **Geen overlap of andere relevante afhankelijkheid?** Ga automatisch verder met toegestane ontwikkeling en read-only voorbereiding, zonder rituele `go`-vragen of nieuwe afstemming over parallelle menselijke deploys. Gebruik de reeds bewezen baselines. Iedere merge volgt de actuele AGENTS.md en exact geldige eigenaarautorisatie; een veranderde hoofdcommit wordt niet stilzwijgend in een productieautorisatie vervangen.
- **Overlap of onverwachte remote drift?** Blokkeer alleen de getroffen releaseactie; identificeer exact bestand, betrokken PR/commit, oude/nieuwe SHA en noodzakelijke hercontrole of rebase. Laat andere onafhankelijke werkzaamheden doorgaan. Rebase of manifestvernieuwing mag geen bestaande productietoestemming stilzwijgend hergebruiken.
- Bij onzekerheid over een mogelijk herschreven `main`: controleer echte commit-ancestry voordat je force-push of fout beweert; een andere HEAD door parallel werk is niet verdacht.
- Bewaar de fail-closed productiegrenzen: upload, verwijdering, SQL-migratie, secrets/rechten en security-gatewijzigingen vragen elk de bestaande specifieke autorisatie, immutable actuele commit, SHA-256-baselines en rollback/verify waar van toepassing. `go` is geen productietoestemming.
- Meld de gebruiker **alleen concrete conflicten of noodzakelijke beslissingen**, met het minimale bewijs. Geen valse alarmen over normale parallelle voortgang. Probeer niet te claimen dat er continu in de achtergrond wordt gemonitord; controleer bij interactie of via een werkelijk ingestelde automation.


