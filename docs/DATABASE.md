# Database — geverifieerde tabelstructuren

**Project:** veilingcatalogus 41 Lokeren  
**Bron:** phpMyAdmin op de OVHcloud-productiedatabase — door de gebruiker aangeleverde uitvoer van `SHOW CREATE TABLE lots;` en `SHOW CREATE TABLE lot_images;` op **18 oktober 2026**.  
**Status:** de tabellen `lots` en `lot_images` zijn als DDL bevestigd. De overige tabellen (`settings`, `admins`, `login_attempts`) zijn nog **niet** als schema gedocumenteerd.

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

## Tabel `lot_images`

**Bron:** screenshot van de volledige MySQL-uitvoer `SHOW CREATE TABLE lot_images;` (18 oktober 2026). De huidige productie-tellerstand `AUTO_INCREMENT=11` is bewust niet in de herbruikbare DDL opgenomen.

~~~sql
CREATE TABLE `lot_images` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `lot_id` int unsigned NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '1',
  `source_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_source` (`lot_id`,`source_name`),
  KEY `idx_img` (`lot_id`,`sort_order`,`id`),
  CONSTRAINT `fk_lot_image` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
~~~

### Velden en relatie

| Kolom | Type | Betekenis |
|---|---|---|
| `id` | `int unsigned`, PK, AUTO_INCREMENT | Technische sleutel van de afbeelding |
| `lot_id` | `int unsigned`, NOT NULL | Verplichte koppeling naar `lots.id` |
| `path` | `varchar(255)`, NOT NULL | Relatief pad naar het fysieke afbeeldingsbestand |
| `sort_order` | `smallint unsigned`, default 1 | Sorteerpositie; samen met `id` gebruikt voor hoofdfoto |
| `source_name` | `varchar(200)`, NULL toegestaan | Oorspronkelijke bestandsnaam voor importdeduplicatie |
| `created_at` | `timestamp`, default CURRENT_TIMESTAMP | Tijdstip van koppeling |

**Referentiële integriteit:** `fk_lot_image` verbindt `lot_images.lot_id` met `lots.id`; een fotoregistratie kan niet verwijzen naar een niet-bestaand kavel. Bij verwijdering van een kavel verwijdert MySQL via **ON DELETE CASCADE** de bijbehorende fotoregistraties automatisch.

**Belangrijke nuance:** MySQL verwijdert hierbij **niet** de foto uit het bestandssysteem. Het bestaande PHP-kavelbeheer probeert die bestanden apart op te ruimen. Dit gedrag en eventuele verweesde bestanden moeten nog functioneel worden getest.

**Unieke bronnaam per kavel:** `uq_source(lot_id,source_name)` verhindert eenzelfde **niet-NULL** bronnaam tweemaal bij één kavel. Omdat `source_name` NULL mag zijn, laat MySQL meerdere fotoregistraties met NULL toe; dit is van belang voor foto's via de individuele kaveleditor.

**Index:** `idx_img(lot_id,sort_order,id)` ondersteunt ophalen per kavel in hoofdfoto-/galerijvolgorde. **Engine/collation:** InnoDB en utf8mb4_unicode_ci.

**T-032 geslaagd (18-10-2026):** tabelstructuur, foreign key en indexen zichtbaar in aangeleverde phpMyAdmin-screenshot. Dit is een **schemaverificatie**, niet een bewezen werking van verwijderen en bestandssynchronisatie.

### Nog te verifiëren

- Een duplicaat via de kaveleditor toont de gewenste foutmelding (T-003).
- CSV-upsert met bestaande lotnummers werkt zoals bedoeld (T-005).
- Gelijktijdige bewerkingen blijven correct en gebruikersvriendelijk.
- Schema's, foreign keys en constraints van de overige drie tabellen (`settings`, `admins`, `login_attempts`) zijn nog niet aangeleverd.
- Test van cascaderende verwijdering en opruimen van fysieke afbeeldingsbestanden in een testomgeving (T-033).
- Bij wijzigingen aan het schema moeten deze documentatie, `docs/REQUIREMENTS.md`, `docs/TESTPLAN.md` en `CHANGELOG.md` samen worden bijgewerkt.

> **Niet uitvoeren op productie:** deze DDL is voor documentatie/reproduceerbaarheid, niet bedoeld als migratiescript op een bestaande tabel. Gebruik voor toekomstige schemawijzigingen afzonderlijk gereviewde migraties en maak eerst een back-up.
