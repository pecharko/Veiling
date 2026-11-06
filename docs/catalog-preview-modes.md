# Veiling / Marktplaats: omschakelen in adminvoorbeeld
Via **Bekijk website** in het adminmenu opent een geauthenticeerd adminvoorbeeld. De gele balk bevat **Opsplitsing aan** en **Opsplitsing uit**. Beide zijn CSRF-beveiligde POST-acties en vereisen een actieve adminvoorbeeldsessie. De keuze is uitsluitend per beheersessie opgeslagen en wijzigt geen instellingen in de database of de publieke site. Bij **Voorbeeld sluiten** wordt de keuze gewist.

**Uit:** alle zichtbare kavels in één overzicht, zonder marktprijzen of startboden, ook op de detailpagina. **Aan:** tabbladen Veiling en Marktplaats; veilingnummers en oplopende veilingvolgorde, alleen startbod voor veilingkavels, alleen marktprijs voor marktplaatskavels. Voor de lokale preview zijn alle kavels fictief.

Na definitieve acceptatie kan een afzonderlijke admininstelling voor publieke activering worden ontwikkeld en later alleen met aparte merge-, database- en productieautorisatie worden uitgerold.
