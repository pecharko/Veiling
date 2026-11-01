# Beveiligde dispatchintegratie — dormant implementatievoorstel

Deze wijziging implementeert de samenhangende dispatchketen, maar activeert hem
niet. `owner_bound_dispatch.execute()` retourneert onvoorwaardelijk `DISABLED`.
De bestaande `verified_deploy_controller.py`, registry en productieworkflow
blijven ongewijzigd. Er worden geen credentials, rechten, secrets, productie-DB,
SFTP-verbindingen of live workflows toegevoegd. De enige workflowwijziging is
offline CI met `contents: read`, ook bij een push op `main`.

## Implementatie en volgorde

1. `GitHubEvidence` haalt zelfstandig repository-eigenaar, issue en comment op
   via de vaste GitHub REST-repository. Zowel numerieke account-ID als login en
   accounttype moeten bij de eigenaar horen. Het exacte ownercomment bevat de
   bestaande approvalvelden en een laatste regel `nonce=<64 lowercase hex>`.
   De eigenaar moet de nonce onafhankelijk genereren met 32 random bytes.
   Een JSON-ticket of aangeleverde bewijsboolean verleent geen bevoegdheid.
2. De bestaande `collect_review()` controleert eigenaar, ongewijzigd comment,
   open/nooit verbruikte issue, geldigheid van 24 uur, immutable `main`, exact
   manifest en authentiek preflightartifact, bronhashes, remote hashes/ABSENT
   en de bestaande runtime-bestandsallowlist. Legacy approvals zonder nonce
   blijven offline reviewbaar; de dispatchintegratie weigert ze.
3. De nieuwste run van **beide** vaste workflows `php-lint.yml` en
   `owner-bound-dispatch-offline-tests.yml` moet een geslaagde onafhankelijke
   `push` op dezelfde `main` SHA zijn. Forks, herhaalde runpogingen, ontbrekende,
   verlopen, toekomstige, gepagineerde en nog lopende evidence blokkeren.
   Ownerconsent moet na voltooiing van die CI liggen. Een oudere groene run
   maskeert geen nieuwere rode run.
4. Eerdere targetruns op dezelfde main SHA en actieve targetruns blokkeren.
   Actieve handmatige workflows in queued/in_progress/waiting/pending/requested
   blokkeren eveneens, conservatief ook als zij alleen read-only zouden zijn.
   Alle inventories zijn begrensd en weigeren onvolledige paginering.
5. De lokale transactionele `Ledger` reserveert atomisch de nonce, consent-ID
   (repo/issue/comment), release-ID en een globale actieve claim. De opgeslagen
   binding bevat repo, owner-ID, issue/comment, nonce, main SHA, manifestdigest,
   digest van de exacte consenttekst, alle source/remote triples en push-CI-IDs.
   Onafhankelijke processen kunnen samen maar één reservering winnen.
6. Na de duurzame reservering wordt de **hele** evidenceketen opnieuw opgehaald
   en vergeleken. Drift brandt de claim op; dezelfde release/consent/nonce kan
   niet opnieuw worden gebruikt. Vervolgens wordt `ATTEMPTED` duurzaam vastgelegd
   voordat de transportfunctie wordt aangeroepen.
7. Het transport doet één HTTPS-POST naar uitsluitend
   `pecharko/Veiling/actions/workflows/shared-ovh-release.yml/dispatches`, ref
   `main`, met precies `release_id` en `confirmation=DEPLOY-<id>-<main SHA>`.
   Geen redirects, instelbare workflow, ref, extra inputs of retries. Een 204
   betekent alleen API-acceptatie. Elke fout of onduidelijke uitkomst betekent
   `STOP_NO_RETRY`, ook als de reservering of POST mogelijk toch slaagde.

De private `_attempt_once()` seam verbindt deze componenten voor offline tests.
Hij is geen geactiveerde service-ingang. Python-private namen zijn geen
beveiligingsgrens: de echte grens blijft de afzonderlijk goedgekeurde runtime,
credentialverstrekking en service-authenticatie. Nooit rechtstreeks vanuit
chat, pull-requestcode of een ongeverifieerd event aanroepen.

## Opslag- en activatiegrens

De SQLite-adapter is concreet uitvoerbaar en getest met heropenen en meerdere
processen. Hij is uitsluitend geschikt voor één afzonderlijke vertrouwde
controllerhost met een vooraf geprovisioneerde private lokale persistente disk
met betrouwbare fsync/locking. **Niet geschikt voor GitHub-hosted runners,
ephemeral disks, netwerkfilesystems of meerdere onafhankelijke ledgerkopieën.**
De dispatcher provisiont of reset nooit zelf een ledger. `synchronous=FULL`,
`BEGIN IMMEDIATE` en SQL-UNIQUE constraints verzorgen de lokale garanties.
Er bestaat geen unreserve/delete/takeover/retry-operatie. Verlies, vervanging,
verwijdering of terugzetten van een oude ledger kan replaypreventie breken;
herstel van storage mag daarom nooit automatisch de service herstarten.

Vóór aparte activatiegoedkeuring moeten host/storage, bestandsrechten,
anti-rollback/retentie, crashherstel, actorisolatie, externe authenticatie van
de service-ingang en minimale aparte read/dispatchcredentials onafhankelijk
bewezen worden. De dispatcher mag zijn ledger of eigen beschermingsbeleid
niet vervangen. Deze PR configureert geen van die externe voorzieningen.
De bestaande website-MySQL wordt niet gebruikt of gewijzigd.

## Reconciliatie en veilige stilstand

`reconcile()` leest de persistente claim en zoekt uitsluitend via GET naar
targetruns met dezelfde immutable SHA en juiste repo/workflow/event/attempt.
Het resultaat onderscheidt **kandidaat-run**, API-acceptatie, uitvoering,
onafhankelijke OVH-readback en browseracceptatie.

De bestaande GitHub POST geeft geen uniek run-ID terug, en de bestaande target
echoot geen nonce. Daarom bewijst zelfs één passende run niet welke POST hem
startte of welke release-input hij kreeg. `dispatch_identity_confirmed` blijft
false. Geen kandidaat, meerdere kandidaten of succesvolle CI geven nooit
retrytoestemming. De actieve claim blijft geblokkeerd, ook na een succesvolle
kandidaat-run. Dit voorstel kiest bewust veilige stilstand; er is geen
automatische claimvrijgave of volgende live dispatch na een poging.

Een toekomstige run-correlatie of geautoriseerde lockvrijgave vereist aparte
exacte scope en review. Verwijder de oude claim nooit om opnieuw te dispatchen.
Productieworkflowbescherming, fresh driftgate vóór elke upload, pinned hostkey,
per-file hashes, back-up, rollback, readback en afzonderlijke onafhankelijke
evidence/browseracceptatie blijven volledig bij de bestaande releaseprocedure.
Bij main-drift na de laatste controle weigert de bestaande exacte
confirmation/SHA-gate van de target de uitvoering.

## Regressies en scope

`tests/owner_bound_dispatch_integration_test.py` doorloopt de keten met een
nagebootste GitHub-API en echte tijdelijke SQL-opslag. Getest worden onder meer
default-disabled gedrag zonder I/O, eigenaar/CI/manifest/source/history-drift,
nonce-eisen, parallelle processen en dispatchpogingen, replay na heropenen,
commit-onzekerheid, crashvensters, één vaste POST, redirects, time-out,
onzeker resultaat na verzending en read-only kandidaat-reconciliatie.
De bestaande offline review-, adapter- en evidence-tests blijven van toepassing.

Er hoort geen `releases/<id>.json` bij deze gespecialiseerde controllerwijziging:
geen allowlisted website-runtimebestanden worden uitgerold. Merge, activatie,
rechten/credentials en productie-uitrol blijven afzonderlijke eigenaarbesluiten.
