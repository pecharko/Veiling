# Database — geverifieerde tabelstructuren

**Project:** veilingcatalogus 41 Lokeren  
**Bron:** phpMyAdmin op de OVHcloud-productiedatabase — door de gebruiker aangeleverde uitvoer van `SHOW CREATE TABLE lots;` op **18 oktober 2026**.  
**Status:** uitsluitend tabel `lots` als DDL bevestigd. De overige tabellen (`settings`, `admins`, `lot_images`, `login_attempts`) zijn nog **niet** als schema gedocumenteerd.

Deze documentatie bevat **uitsluitend structuur**, geen veilingrecords, persoonsgegevens of databasewachtwoorden.

## Tabel `lots`

De onderstaande SQL weerspiegelt de getoonde tabeldefinitie. Het productiegebonden `AUTO_INCREMENT=11` aan het einde is bewust weggelaten: het is de huidige tellerstand op het moment van de screenshot, geen vereiste voor het schema.

```sql
CREATE TABLE `lots` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `lot_number` int unsigned NOT NULL,
  `title` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtitle` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `category` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Overige',
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `estimate_min` decimal(12,2) DEFAULT NULL,
  `estimate_max` decimal(12,2) DEFAULT NULL,
  `status` enum('draft','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lot_number` (`lot_number`),
  KEY `idx_public` (`status`,`lot_number`),
  KEY `idx_category` (`status`,`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Betekenis van de belangrijkste velden

| Kolom | Type | Betekenis |
|---|---|---|
| `id` | `int unsigned`, PK, AUTO_INCREMENT | Technische interne sleutel |
| `lot_number` | `int unsigned`, UNIQUE, NOT NULL | Publiek uniek lotnummer |
| `title` | `varchar(250)`, NOT NULL | Titel van het kavel |
| `subtitle` | `varchar(250)`, standaard lege string | Aanvullende titel/informatie |
| `category` | `varchar(120)`, standaard `Overige` | Categorie |
| `description` | `text`, NOT NULL | Beschrijving |
| `details` | `text`, NOT NULL | Details, afmetingen en staat |
| `estimate_min`, `estimate_max` | `decimal(12,2)`, NULL toegestaan | Minimum- en maximumschatting |
| `status` | `enum('draft','published')`, default `draft` | Publicatiestatus |
| `created_at`, `updated_at` | `timestamp` | Aanmaak- en wijzigingsmoment |

### Indexen en constraints

- **`PRIMARY KEY (id)`**: uniek intern ID.
- **`UNIQUE KEY lot_number (lot_number)`**: een lotnummer kan slechts eenmaal voorkomen; databasegarantie voor **FR-003**.
- **`idx_public (status, lot_number)`**: index om publieke kavels te selecteren/ordenen.
- **`idx_category (status, category)`**: index voor zichtbare kavels per categorie.
- **InnoDB** ondersteunt transacties voor CSV-import, bewerkingen en herstel; dit is op zichzelf geen bewijs dat alle applicatiebewerkingen correct transactioneel zijn.

### Verificatie FR-003

1. `SHOW INDEX FROM lots;` liet voor `lot_number` `Non_unique=0` en `Seq_in_index=1` zien (**T-026 geslaagd**).
2. `SELECT lot_number, COUNT(*) AS aantal FROM lots GROUP BY lot_number HAVING COUNT(*) > 1 ORDER BY lot_number;` retourneerde nul rijen (**T-030 geslaagd**).
3. `SHOW CREATE TABLE lots;` bevestigde de volledige kolommen, indexen, datatypen en defaults (**T-031 geslaagd**, 18 oktober 2026).

Deze controles zijn **alleen-lezen**; er is geen schemawijziging uitgevoerd.

### Nog te verifiëren

- Een duplicaat via de kaveleditor toont de gewenste foutmelding (T-003).
- CSV-upsert met bestaande lotnummers werkt zoals bedoeld (T-005).
- Gelijktijdige bewerkingen blijven correct en gebruikersvriendelijk.
- Schema's, foreign keys en constraints van de overige vier tabellen zijn nog niet aangeleverd.
- Bij wijzigingen aan het schema moeten deze documentatie, `docs/REQUIREMENTS.md`, `docs/TESTPLAN.md` en `CHANGELOG.md` samen worden bijgewerkt.

> **Niet uitvoeren op productie:** deze DDL is voor documentatie/reproduceerbaarheid, niet bedoeld als migratiescript op een bestaande tabel. Gebruik voor toekomstige schemawijzigingen afzonderlijk gereviewde migraties en maak eerst een back-up.
