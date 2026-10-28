# Gecombineerde release: wachtwoordreset en mailtest-opruiming

## Vastgelegde scope
Vier bestanden via bestaande OVH-release met exact hashmanifest:
- catalogus-public/admin/login.php
- catalogus-public/admin/wachtwoord-vergeten.php
- catalogus-public/admin/wachtwoord-reset.php
- private/admin_password_reset.php

De tijdelijke catalogus-public/admin/mailtest-eenmalig.php wordt via de afzonderlijke geautoriseerde verwijderingsworkflow verwijderd, niet via het generieke uploadmanifest. Dit zijn technisch twee acties, met één gezamenlijke functionele acceptatie.

## Minimale databasevoorwaarde
Geen algemene schema-compatibiliteitsstudie. Controleer uitsluitend read-only of admin_password_resets bestaat. Indien afwezig, maak eerst een gecontroleerde back-up en pas uitsluitend database/admin_password_reset_migration.sql toe na afzonderlijke expliciete autorisatie. De code mag niet actief worden vóór de tabel bestaat. Geen automatische SQL-uitvoering via HTTP of tijdens deployment.

## Snapshotveiligheid
private/snapshots.php is beschermd tegen standaarddeployments. De repository bevat reset-tokeninvalidatie bij snapshotherstel, maar er is nog geen goedgekeurde uitzondering voor dit beschermde bestand. Behoud die bescherming; activeer de reset niet zolang een herstel oude links kan heractiveren. Behandel dit via een afzonderlijk beoordeeld beveiligd releasepad of bewijs een equivalent veilig alternatief.

## Productiegates
De upload en verwijdering vereisen elk actuele immutable main-SHA, exacte hashes en expliciete productieautorisatie. Bewaar rollbackartifacts ten minste zeven dagen. Vóór functionele acceptatie: controleer dat de tijdelijke pagina verdwenen is, resetmail aankomt, link eenmalig bruikbaar is, wachtwoord veranderd wordt, oude sessies ongeldig zijn en gedeactiveerde accounts niet kunnen resetten. Geen rechten/secrets wijzigen.
