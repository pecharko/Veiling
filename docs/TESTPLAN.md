# Testplan — veilingcatalogus 41 Lokeren

**Status:** testcases voorbereid op basis van eerdere projectbeschrijving; **nog geen uitvoering of succes claimen**. Vul bij uitvoering datum, omgeving, commit, resultaat en opmerkingen in.

| Test-ID | Requirement | Testscenario | Verwacht resultaat | Uitvoering |
|---|---|---|---|---|
| T-001 | FR-001 | Adminpagina openen zonder login | Login vereist, geen afgeschermde informatie zichtbaar | Niet uitgevoerd |
| T-002 | FR-002/004 | Nieuwe kavel maken, geldige titel en schatting | Kavel wordt bewaard en heropenbaar | Niet uitgevoerd |
| T-003 | FR-003 | Tweede kavel met bestaand lotnummer bewaren | Duidelijke fout; geen tweede identiek lotnummer | Niet uitgevoerd |
| T-004 | FR-003 | Bestaande kavel bewerken zonder lotnummer te wijzigen | Bewerking slaagt | Niet uitgevoerd |
| T-005 | FR-003/009 | CSV importeren met dubbele lotnummers | Geen stille duplicaten; begrijpelijke melding | Niet uitgevoerd |
| T-006 | FR-005/012 | Concept en gepubliceerd; catalogus aan/uit | Alleen bedoelde kavels worden publiek zichtbaar | Niet uitgevoerd |
| T-007 | FR-006/007 | Geldige en ongeldige foto's uploaden/verwijderen | Alleen geldige foto's geaccepteerd; veilige verwijdering | Niet uitgevoerd |
| T-008 | FR-008 | Meerdere foto's met verschillende sorteerwaarden | Laagste sorteerwaarde is hoofdfoto | Niet uitgevoerd |
| T-009 | FR-009/010 | CSV- en fotobulkimport met fouten | Heldere melding, geen onbedoelde dataverlies | Niet uitgevoerd |
| T-010 | FR-011 | Veilinginfo bewerken en herladen | Alle velden correct opgeslagen en getoond | Niet uitgevoerd |
| T-011 | FR-015/016 | Snapshot maken, tonen, downloaden | ZIP beschikbaar en compleet | Niet uitgevoerd |
| T-012 | FR-017 | Snapshot terugzetten in geïsoleerde testomgeving | Database en foto's correct hersteld; geen half herstel | Niet uitgevoerd |
| T-013 | FR-017 | Ongeldige/beschadigde ZIP aanbieden | Herstel wordt veilig geweigerd | Niet uitgevoerd |
| T-014 | FR-017 | Veiligheidskopie vóór herstel controleren | Originele toestand veilig herstelbaar | Niet uitgevoerd |
| T-015 | FR-018/019 | Opslagdashboard vergelijken met OVHcloud Manager | Verschillen verklaard; labels kloppen | Niet uitgevoerd |
| T-016 | FR-019 | Drempels 79%, 80%, 89%, 90% simuleren | Correcte waarschuwingsniveaus | Niet uitgevoerd |
| T-017 | FR-020 | Snapshot met onvoldoende vrije ruimte testen | Snapshot veilig geweigerd | Niet uitgevoerd |
| T-018 | FR-021 | Elk beheeronderdeel openen | Back-up & herstel blijft in menu zichtbaar | Niet uitgevoerd |
| T-019 | FR-022 | Beheer-POST zonder geldig CSRF-token | Actie geweigerd, geen datamutatie | Niet uitgevoerd |
| T-020 | NFR-003 | Mobiel scherm en toetsenbordbediening | Velden en acties bruikbaar, geen overlap | Niet uitgevoerd |
| T-021 | NFR-001 | Geplande commit op secrets/gegevens scannen | Geen credentials, SQL-dumps, foto's of snapshots | Niet uitgevoerd |

## Registratie bij uitvoering

Gebruik per testronde dit sjabloon:

```text
Datum:
Omgeving: lokaal / test / productie (productie: alleen niet-destructieve tests)
Git-commit:
Test-ID:
Resultaat: geslaagd / mislukt / geblokkeerd
Bewijs / notities:
Actiehouder en eventuele follow-up:
```

**Let op:** voer T-012 t/m T-017 alleen uit met geschikte testdata of in een geïsoleerde testomgeving. Test geen risicovolle herstelactie rechtstreeks op productie.
