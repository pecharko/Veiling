# Controller: automatische bewijsregistratie, zonder nieuwe deployrechten

Status: ontwerp / read-only; geen autorisatie om de live writer, workflowrechten of productieomgeving te wijzigen.

## Bestaande scheiding op main (27 oktober 2026)

- `.github/workflows/verified-deploy-controller.yml` ontvangt owner-issuecomments en kan onder de bestaande gates Actions starten. Het script `scripts/verified_deploy_controller.py` is een bestaande productiegevoelige component.
- `scripts/shared_ovh_controller_adapter.py` is uitsluitend een offline contract en vermeldt uitdrukkelijk dat het niet met de uitvoerende controller verbonden is.
- `scripts/shared_ovh_controller_evidence.py` verzamelt read-only GitHub-bronnen voor een inert reviewplan; het verstuurt geen comments en doet geen dispatch.
- De CI-impact-NVT-route is apart getest met PR #262; de twee integratie-suites rapporteerden aantoonbaar NOT_APPLICABLE, terwijl de always-on PHP-check groen werd.

## Veilige eerstvolgende implementatiefase: observatie vóór automatisering

1. Voeg op een aparte PR een **read-only observatierapport** toe dat via de bestaande GitHub-evidencecode de laatst relevante controller- en release-runs afleidt uit expliciet opgegeven workflow/run-identiteiten. Bind iedere conclusie aan de exacte run-id, head-SHA, event, jobnaam, status, conclusie en immutable artefact-identiteit.
2. Gebruik een strikt enum voor `PENDING`, `VERIFIED`, `FAILED`, `INCOMPLETE` en `NOT_APPLICABLE`; behandel absent/skipped/cancelled, mismatched SHA, verlopen artifact, niet-zichtbare jobs of incomplete paginering niet als groen. `NOT_APPLICABLE` is alleen geldig bij bewezen niet-functionele PR-diff en zichtbare actieve suitejob.
3. Rapporteer afzonderlijk: preflight voltooid, expliciete eigenaarautorisatie aangetroffen, dispatch gestart, deployjob voltooid, upload/readback bewijs, rollbackartifact, browseracceptatie. Een succesvolle workflow-run bewijst niet automatisch dat alle productiebestanden en rollback gecontroleerd zijn.
4. Maak een offline fixture-suite met succes, NVT, inconclusief, drift, duplicate evidence, gedeeltelijke upload, WAF HTTP 403, ontbrekend rollbackartifact en dubbele issue-comment events. Gebruik uitsluitend gemockte GitHub-responses. Geen GitHub-write, geen OVH/SFTP.
5. Publiceer pas daarna een reviewbaar voorstel voor een afzonderlijke write-/dispatchfase. Die fase vereist exacte eigenaarautorisatie, bestand-allowlist, immutable main-SHA, bewezen remote SHA-256/ABSENT, pre-write driftcontrole, SSH-hostkey pinning, rollback (7 dagen), one-shot issue-consumptie en een expliciet gecontroleerde workflow-permission/merge-review.

## Acceptatiecriteria voor read-only implementatie

- Eén machineleesbaar bewijsrapport én een menselijke samenvatting, met per claim controleerbare herkomst.
- Een falende/onbekende bron leidt nooit tot `VERIFIED`.
- Geen nieuwe rechten, tokens, secrets, workflow_dispatch of issue-comment writes.
- Alle drie bestaande verplichte PR-CI-statussen blijven zichtbaar; NVT telt niet als geslaagde integratietest.
- De implementatie-PR met controller-/evidencecode wordt **niet automatisch gemerged**: aparte securityreview en exacte-SHA-mergegoedkeuring.

## Niet in deze documentatie-PR

Geen runtimewijzigingen, geen deployworkflowaanpassingen, geen OVH-upload, geen databaseactie, geen cron, geen gewijzigde releasegate en geen automatische dispatch.
