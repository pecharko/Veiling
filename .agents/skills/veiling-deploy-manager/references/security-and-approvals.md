# Security And Approvals

Deze referentie bevat bestaande instructies en casussen, zonder nieuwe autorisatie. Volg eerst SKILL.md en het actuele repositorycontract.

> Codex-aanvulling 31 oktober 2026: de actuele repository-AGENTS.md vereist een afzonderlijke mergebeslissing voor iedere PR. Historische passages over automatisch doorlopen na een gewone merge creëren geen mergebevoegdheid. Veilige ontwikkeling en geïsoleerde tests mogen binnen de gebruikersopdracht volledig worden voorbereid; zie `references/codex-development.md`.

## Single-deployer productieprofiel (27 oktober 2026)

**Feitelijke aanname:** de gebruiker is de enige persoon die bewust naar productie op OVH deployt. Gebruik dit om het proces eenvoudiger te maken, **niet** als bewijs dat de server nooit verandert. GitHub workflows, hostingproviders, vorige releases, noodherstel, handmatige SFTP-handelingen en een ondertussen gewijzigde `main` kunnen nog altijd drift veroorzaken.

### Lichte release en single-deployer optimalisatie

- Bij een eenvoudige UI-/tekst-/CSS-wijziging zonder auth, data, schema of infrastructuur: één compacte PR met alleen werkelijk gewijzigde bestanden; CI afwachten; bij veilige normale code volgens bestaande mergebevoegdheid automatisch verder. Geen onnodige nieuwe releaseworkflow, helper-script, aparte status-PR of herhaalde `go`-vraag als de gedeelde manifest-gestuurde releaseworkflow het bestandstype toelaat.
- Maak na merge rechtstreeks één releasevoorstel: exacte immutable `main`-SHA, relevante bestanden, hun source SHA-256, de laatst bewezen remote hashes (inclusief `ABSENT`), link naar passende workflow, rollbackmechanisme en eventuele browseracceptatie. Bereid alle toegestane read-only controles zelf voor. Minimaliseer handmatige velden en geef de gebruiker hoogstens één concrete autorisatiehandeling.
- **Optimistische concurrency:** een eerdere read-only SFTP-baseline mag als voorgestelde baseline dienen wanneer er geen aanwijzing voor een andere deployment is. Herhaal de volledige externe preflight niet louter omdat er tijd verstreken is. **Verplicht** onmiddellijk voor iedere write binnen de deployment-job remote SHA-256/`ABSENT` opnieuw lezen en strikt vergelijken met de expliciet geautoriseerde baseline; stop fail-closed bij afwijking, ontbrekend bewijs of veranderde `main`. De nieuwe check vervangt geen back-up/rollback.
- Is een remote baseline nog helemaal niet betrouwbaar bewezen, voer één echte read-only SFTP-preflight uit. Geen hash raden. Bij `groen` probeer zelfstandig jobs/log/artifact te verifiëren; vraag nooit naar een run-ID. Zonder bronbewijs markeer de baseline als onbevestigd en ga verder met uitsluitend veilige voorbereidingen. De gedeelde writer moet vóór upload alle expliciet goedgekeurde hashes bewijzen.
- Gebruik standaard de gedeelde manifest-gestuurde uploader met exact de nodige paden. Ga alleen voor een uitzonderlijk type wijziging naar een gespecialiseerde workflow; nooit een brede bestandsupload voor een kleine wijziging, geen onbedoelde writes en geen automatische deploy na merge.
- **Één menselijke productieautorisatie per release** blijft vereist en moet exact bestand(en), immutable commit, baseline en rollback omvatten. `go`, PR-merge, `groen`, of eerdere productieautorisaties gelden daarvoor niet. De gebruiker mag bij een latere expliciete actie kiezen om dezelfde gedeelde release meteen in een handmatige workflow uit te voeren; vraag niet nogmaals voor dezelfde handeling die al expliciet en exact is goedgekeurd. Productie database/cron/restore/security-acties behouden elk hun eigen specifieke toestemming.
- Na de release: lees GitHub-run, match werkelijk gewijzigde bestandsnamen en `VERIFIED` SHA, controleer rollbackartifact en vraag een compacte browseracceptatie. Leg eenmaal vast in issue/PR. Laat technische preflight en fysieke productieuitvoering duidelijk gescheiden.

### Beslisregel over conflictcontrole

Single-deployer verlaagt de kans op **gelijktijdige menselijke deployments**. Daarom zijn extra afstemmingsrondes, extra handmatige preflights en opnieuw opgevraagde toestemming zonder verandering van scope meestal overbodig. Het elimineert **geen** serverdrift of race tussen preflight en upload: de geautomatiseerde serverdrift-gate, immutable SHA, pinned SSH hostkey, per-file allowlist, beperkte rechten, codeback-up, rollback, post-upload hashcontrole en aparte productieautorisatie zijn nooit optioneel.


## Harde productie- en datagrenzen

**Nooit automatisch uitvoeren op basis van `go`, merge, CI of read-only preflight:** productie-SFTP-write, live code-deployment, database SQL-write, archivering met DELETE, snapshotrestore, reset, verwijdering, cron activeren/wijzigen, OVH-secrets/securitywijzigingen of live CLI-uitvoering die data kan veranderen. Elke dergelijke stap vereist een **nieuwe, expliciete, specifieke finale autorisatie**, ook na eerder goedgekeurde code-merge. Maak voor databasewijziging eerst expliciet de afzonderlijke keuze voor data-only back-up, waar vereist, en voer geïsoleerde hersteltesten uit met passende toestemming. Voer nooit productiearchivering uit als smoke-test. Leg feitelijke stages gescheiden vast: voorbereid, CI groen, gemerged, uitgerold, geverifieerd.


## OVH Application Firewall (bewezen 26 oktober 2026)

GitHub-hosted runners krijgen vanaf OVH Application Firewall HTTP 403 voor `https://41lokeren.be`, ook voor de homepage en admin-login. Voorbeelden: runs `40327048692`, `40326235254`. Dit is geen bewijs van publieke uitval en telt **niet** als geslaagde HTTPS-smoketest. Diagnoseer geen bewezen WAF-probleem opnieuw; laat firewall aan, omzeil niet met spoofing/alternatieve endpoints en accepteer 403 nooit stilzwijgend als groen. De eerder afgesproken onafhankelijke handmatige browser-pre-/postacceptatie is afzonderlijk te rapporteren en vereist waar passend. Wijziging van deze beveiligingsgate vereist aparte risicoreview en toestemming.


## Rapportage en safety

Verifieer elke GitHub-operatie met de echte connectoruitkomst. Claim nooit een push-run, preflight, artifact, release of succes zonder bewijs. Bij geblokkeerde connectoracties niet omzeilen; documenteer minimaal benodigde handmatige actie. Toon exact de laatste afgeronde fase en ga waar mogelijk meteen verder. Verander productie- of securitygates niet om CI groen te krijgen.

