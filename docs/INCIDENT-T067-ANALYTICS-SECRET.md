# T-067: analytics-secret installation incident (2026-10-21)

## Bevestigd
- Mislukte productierun 39606484975 bij de SFTP-installatiestap; offline tests groen.
- Oude workflow verborg de precieze uitzondering achter een generieke STOP-regel.
- Read-only herinspectie 39607463597: PHP geldig, sleutel afwezig en config SHA-256 ongewijzigd.
- Publieke website en admin-login werken volgens de beheerder.
- Mogelijk resterend tijdelijk SFTP-bestand was niet gecontroleerd.

## Oorzaak
Nog niet bewezen. Mogelijke fases: tijdelijk bestand openen, rechten instellen, schrijven, verificatie, POSIX rename.

## Verbetering in deze PR
- Diagnose meldt alleen een vooraf vastgelegde fasenaam; geen exceptionstrings, secrets of config-inhoud.
- Read-only inspectie telt eventueel overgebleven tijdelijke bestanden, zonder hun inhoud of naam te tonen.
- Geisoleerde regressietests testen veilige foutmeldingen en stoppen bij baseline drift.
- Geen workflow-aanpassing, geen productie-installatie en geen wijzigingen aan OVH.

## Volgende stappen
1. CI en securityreview; PR handmatig mergen.
2. Via afzonderlijke handmatige inspect-run uitsluitend productie lezen en eventueel temp-aantal noteren.
3. Alleen met nieuwe aparte toestemming een installatie overwegen. Niet zomaar rerunnen.
4. Geen tijdelijke bestanden verwijderen zonder aparte toestemming.
