# OVH Starter: eenmalige mailproef, alleen na afzonderlijke productieautorisatie

Deze kleine adminpagina werkt via HTTPS en bestaande Administrator-authenticatie en CSRF. Er is geen SSH of SMTP-secret nodig. Ze staat niet in het menu. De operator moet eerst inloggen, de exacte bevestiging intypen en zelf op verzenden klikken.

**Nog niet gedeployed of uitgevoerd.** De bestaande CLI-probe PR #319 is niet geschikt voor OVH Starter en blijft ongemerged. Upload uitsluitend via apart goedgekeurde deployment met manifest, SHA-baselines, driftcontrole, rollback en HTTPS-checks.

De test bewaart voor de poging een eenmalige marker buiten de publieke root. Bij geen schrijfrechten blijft het veilig geblokkeerd. De marker blijft na uploadrollback eventueel bestaan; verwijder hem niet zonder aparte beoordeling. Controleer handmatig in Gmail de afzender/ontvangst; `mail()` true is geen leveringsbewijs.

Na acceptatie de tijdelijke adminpagina met een aparte, gecontroleerde code-release verwijderen. Geen wijziging van database, login of wachtwoordresetactivatie.
