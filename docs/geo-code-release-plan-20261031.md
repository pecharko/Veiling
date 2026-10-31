# Geostatistieken — voorbereiding van één seriële code-rollout

Status: uitsluitend reviewbare manifest-/documentatievoorbereiding. Geen deployment, productie-SQL, herstel, cronactie, dependencyprovisioning of activering uitgevoerd of toegestaan door dit document.

## Vast gebruikersprofiel

De gebruiker is de enige deployer en draait nooit twee deploys parallel (uitdrukkelijk bevestigd op 31 oktober 2026). Werk als één seriële keten onder diens beheer. Er is geen extra afstemming over andere deployers of parallelle menselijke deploys nodig. Hergebruik de bewezen baselines en vraag niet opnieuw of dit profiel geldt. Ontwikkel-PRs/CI kunnen parallel lopen; de bestaande exacte commit-/drift-/back-up-/rollback-/readbackgates blijven ongewijzigd.

## Bron en bewijs

De 18 runtimebronnen zijn beoordeeld en gemerged in #396. PR #486 autoriseerde uitsluitend 14 ontbrekende read-only SFTP-baselines en een manifest-PR; #487 legde dat bewijs vast. Bron voor deze voorbereiding: `b64933ebf2f7bd105081792668e105c12d2435ad`. De 43 featurebestanden zijn ongewijzigd gebleven; beide nieuwe manifesten zijn lokaal door de daadwerkelijke bestaande shared-release-validator gecontroleerd.

- Main-push-CI op deze bron: https://github.com/pecharko/Veiling/actions/runs/41186209747 (success).
- 14-file bewijs: run 41185719828, artifact readonly-release-41185719828 (13036977463), opgeslagen in releases/geo-missing-baselines-20261031.json. Twee bestaande bestanden en twaalf geverifieerd ABSENT. Het bot-PR-probleem is opgelost door de bestaande branch te gebruiken; SFTP is niet herhaald.
- Overige drie gewone baselines: automatische read-only run 41186261986, job 124493689370. Snapshotbaseline: run 41186261982, job 124493689285. Geen baseline gegokt of opnieuw opgehaald voor dit plan.
- Dit document is geen finale productieautorisatie. Die moet na eventuele manifestmerge de dan actuele immutable main-SHA vastleggen. Een PR-merge start uitsluitend bestaande CI/read-only checks; geen deploy.

## De seriële keten

| Stap | Inhoud | Bestaand pad | Voorwaarde |
|---|---|---|---|
| A | 10 nieuwe, nog niet aangeroepen geohelpers | shared-ovh-release.yml; release_id geo-helpers-code-20261031 | Alle tien geverifieerd ABSENT; afhankelijkheidsvolgorde in manifest |
| B | 1 bestaande beschermde snapshotfile | snapshot-photos-onefile-release.yml | A technisch geverifieerd; eigen exact goedgekeurde snapshotbaseline/commit |
| C | 7 consumenten | shared-ovh-release.yml; release_id geo-consumers-code-20261031 | A en B technisch geverifieerd; daarna consumenten in manifestvolgorde |

Start B pas na bewezen afronding van A en C pas na bewezen afronding van B. Gebruik overal dezelfde exact geautoriseerde codeversie; geen deployments naast elkaar. De twee JSON-manifesten zijn voorbereide invoer voor de bestaande writer, geen dispatch of toestemming. De beschermde snapshotfile is bewust niet opgenomen in een shared-manifest. Geen nieuwe uploader, controller, allowlist of workflowrecht toegevoegd.

Zonder geoschema/startdatum blijft de geografische functie UIT; de nieuwe consumers activeren geen schema of geolocatie. De normale bestaande bezoekersregistratie blijft functioneel. Bij een bezette catalogusmutex worden beide best-effort tellers overgeslagen, zodat snapshot/herstel niet met een nieuwe tellertransactie kruist.

### A — helpers

| Pad | Source SHA-256 | Remote SHA-256 / ABSENT |
|---|---|---|
| `private/visitor_geo_state.php` | `2afabcd7066175ffa6716ef2dfc4281624b60b3e28e1255d00d09fd6fe6acb85` | `ABSENT` |
| `private/visitor_geo_record.php` | `a5e79c00d2ecf58e19a9fea02e106af6ebb38da24331c37e428a7466e96d1120` | `ABSENT` |
| `private/visitor_geo_lookup.php` | `5d27cb271ed96943db9f66ce85bef0a24469c8bfb0fd71eaa38e2d369f0e7f54` | `ABSENT` |
| `private/visitor_geo_archive_guard.php` | `0db9efda5c4c33d9722a797100fb118b49580e3d65cfca7555d2ac06296be63d` | `ABSENT` |
| `private/visitor_geo_archive.php` | `ee89c3aff88acb2edf73439a5ea82ad98d3a26d82c078d6344ec6864a4d68f1d` | `ABSENT` |
| `private/visitor_geo_report.php` | `8a29d6820890434e48fd28bf5cac12279c3492a6e075d3a68e7924d8976fa642` | `ABSENT` |
| `private/visitor_geo_render.php` | `55ae74b2e56e6ad23e6f521a070223bbf3fc67c26e3955748ff8be341cd29aa0` | `ABSENT` |
| `private/visitor_geo_dashboard.php` | `440a7abe265c2e738e033d1aa4d80156867b4e01af93d97a694cf649bb4730af` | `ABSENT` |
| `private/visitor_geo_delete.php` | `c2cc491d95118615472f391c45567afc99b57bd88fe9d7b2698638edf1622abf` | `ABSENT` |
| `private/visitor_geo_snapshot.php` | `e196f5f4122604adbe97aa13c51c55a5977b69d971f79d7e615268873b64dea9` | `ABSENT` |

### B — beschermde snapshotfile

| Pad | Source SHA-256 | Remote SHA-256 / ABSENT |
|---|---|---|
| `private/snapshots.php` | `7408750ac15cf2ca9cd842df9cbf9a20f1aff43486bf014962aafd9a9cc0017e` | `cf128bb2b1bca241405a6a8e6b8011b2cac610b4876b42f79233e63d9db4f7f6` |

Gebruik de bestaande één-bestands-snapshotroute met nieuw, expliciet gescopeerd eigenaarbesluit voor deze bron en baseline. De oude invitation/password-reset-adapters hebben historische, vastgepinde bronhashes en zijn niet de route voor deze nieuwe inhoud. De bestaande workflow controleert immutable main, bron, remote baseline, back-up/rollback, readback en de per-release browserprecheck; de historische workflowbenaming geeft geen autorisatie.

### C — consumenten

| Pad | Source SHA-256 | Remote SHA-256 / ABSENT |
|---|---|---|
| `private/restore_diagnosis.php` | `bc9765bcb2a8fdc1d489b2a0d18f898957c65e848b2af0adb91aa635ead588ec` | `3beeae2be5639bdca7945af541ac65ca27f8c0ed211420b3db3c3da5b1fe4a41` |
| `private/restore_mixed_diagnosis.php` | `f14c765f0e709b4103a6fe154d21f6c4195f960c36e0e393b21d3baa155e5817` | `ABSENT` |
| `private/visitor_stats_archive.php` | `5691563ee3553153ab2dff15c2c774a5ac62478a8ebcf11aed6e9161b8986354` | `ABSENT` |
| `private/visitor_stats_archive_one_day_experimental.php` | `7f1724eccfcb392a95bba57e284f180dd1cb98c4ba0c0c79c64139e01e0d3879` | `a2a535d18d6f2e58c1e4deaf4f42bbb60757df12858f2ae8efea2ad0a5cb3c57` |
| `private/visitor_stats.php` | `7367ebe9ce9a21604e0abd0faad2065e4975f80ea2991fb3322cc63df42444d6` | `cc854ee0fcb309006ad919f9a6293ad949259765b94e65705040e2d11f7dd82f` |
| `catalogus-public/privacy.php` | `7747a6b1c4a114fa47038c38864d204c27769137bbb671bb88ec1d1855be04d6` | `93e782d3626891ddaf64d57f218a0369de84fffb43529aeb1c6785c6b27da543` |
| `catalogus-public/admin/statistieken.php` | `9bce7b4a9188661962ece6019ae5e2867297bee8ec865164ccae60962631d70c` | `1c79ecc62faa175ccf2a690fce20715434af92db42fc54a939a0ca3552ebd128` |

## Afrondings- en rollbackcontract

- De bestaande writers lezen en vergelijken de expliciet goedgekeurde remote baseline vóór schrijven, bewaren de eerdere code en verifiëren de nieuwe bytes; houd alle ingebouwde gates intact. Een feitelijke afwijking stopt de betrokken stap. Er is geen extra volledige SFTP-preflight nodig enkel vanwege verstreken tijd of een veronderstelde andere deployer.
- Controleer na iedere geautoriseerde run exacte files, VERIFIED-hashes, evidence en rollbackartifact. Een groen plan/CI of een melding van de gebruiker is geen bewijs van upload of browseracceptatie.
- Fout in A: bestaande writer rolt zijn eigen wijzigingen terug. B/C zijn dan niet gestart.
- Fout in B: herstel/controleer de snapshotbaseline via de bestaande rollback. Laat A inert aanwezig; C is niet gestart.
- Fout in C: bestaande writer rolt C terug. Verwijder A niet zolang B daarvan afhankelijk is. Een eventuele volledige coderollback gaat in omgekeerde afhankelijkheidsvolgorde: consumenten, snapshotfile, helpers. Geen schema-/dataherstel uit dit codeplan afleiden.
- Geen coderollback naar geo-onbewuste archivering of snapshots na geoschema-activering zonder afzonderlijk beoordeeld data-/herstelplan.

## Nog afzonderlijk te bewijzen vóór productie en activering

Code-uitrol: actuele hoofdcommit en manifestevidence na merge, juiste hosting-PHP/extensies en vrije ruimte, compatibele bestaande runtime/schema, daadwerkelijke browserprecheck wegens de bekende OVH-WAF 403, back-up/rollback/evidence en de exact gescopeerde finale code-uploadtoestemming. De bestaande workflows/controller blijven de uitvoeringsroute; dit document dispatcht niets.

Migratie/activering later: data-only veiligheidsback-up en getest migratieherstelplan, afzonderlijke productie-SQL-autorisatie, nieuwe geostaat met start NULL, geverifieerd v2-geo-snapshot/herstel op geïsoleerde kloon, gecontroleerd gelicentieerd GeoLite2/readerpakket en quota/leeftijd, vervolgens afzonderlijke starttoestemming voor een toekomstige volledige UTC-dag. Oude snapshots hoeven na upgrade niet herstelbaar te blijven; bestaande bestanden blijven bewaard. De algemene collectie-uitknop stopt beide tellers.

Cron: remote hash bb6dec6a4b82d25084e22df355ccd3da8210461e936c10c5122e4d6e030b67c0 wijkt af van mainhash a2fc3fb943a006a3065bd3b66a923fa04db875ef45e0df4f32780a2877987507. Verifieer de feitelijke versie/status voordat een cronactie wordt voorgesteld; dit plan stopt, uploadt of activeert geen cron. Het ongewijzigde entrypoint mag na geo-activering alleen de geverifieerde geo-bewuste archiefhelper gebruiken. Eventuele croncodewijziging houdt het aparte issue224-pad en de eigen toestemming. Eerste echte archivering met DELETE vereist haar bestaande specifieke autorisatie.

De uiterste bewaartermijn blijft 31 oktober 2027. De HTTP-403 is geen succesvolle websitecontrole en op zichzelf geen bewijs van publieke uitval. Functionele browseracceptatie blijft apart van technisch deploymentbewijs.
