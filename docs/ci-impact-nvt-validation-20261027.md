# CI-impact NVT-verificatie (issue #256)

Deze documentatie-only wijziging is een gecontroleerde PR-proef voor `scripts/ci_pr_impact.py`.

Verwacht bij een PR waarin uitsluitend dit `.md`-bestand onder `docs/` wijzigt:

- `PHP syntax and repository checks`: de altijd actieve controle blijft draaien.
- `Kaveleditor integratietests (geisoleerd)`: `impact` levert `kavel=false`; `kavel-mysql-http` draait, registreert `NOT_APPLICABLE` en start geen MySQL/PHP-integratietests.
- `Snapshot en herstel — geisoleerde tests`: `impact` levert `snapshot=false`; `snapshot-isolated` draait, registreert `NOT_APPLICABLE` en start geen MySQL/PHP-integratietests.

Controleer de daadwerkelijke PR-head, gewijzigde bestanden, jobs, stappen en conclusies in GitHub Actions. `NOT_APPLICABLE` is geen geslaagde integratietest. Bij fouten niet mergen en geen productieactie uitvoeren.
