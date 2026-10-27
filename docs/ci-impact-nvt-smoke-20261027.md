# CI-impact NVT-proef (alleen documentatie)

Deze tijdelijke branch is gemaakt na de goedgekeurde merge van PR #258.

Doel: uitsluitend een nieuw `docs/*.md`-bestand wijzigen en met echte GitHub
Actions-runbewijzen controleren dat de twee bestaande integratiecheckjobs
`kavel-mysql-http` en `snapshot-isolated` wel slagen, maar hun
MySQL-/HTTP-/restorestappen niet uitvoeren (uitkomst
`NOT_APPLICABLE`).

De altijd draaiende PHP-/repositorychecks en offline CI-impacttests moeten
nog steeds groen zijn.

Dit is **geen** autorisatie voor productie, release, wijziging aan bestaande
testgates, rechten, secrets, database of OVH. De proef-PR moet na verificatie
zonder merge worden gesloten.
