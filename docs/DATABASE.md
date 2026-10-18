# Database — geverifieerde tabelstructuren

**Project:** veilingcatalogus 41 Lokeren  
**Bron:** phpMyAdmin op de OVHcloud-productiedatabase — door de gebruiker aangeleverde uitvoer van `SHOW CREATE TABLE` voor **alle vijf** tabellen op **18 oktober 2026**.  
**Status:** de schema's van `lots`, `lot_images`, `settings`, `admins` en `login_attempts` zijn als DDL bevestigd. **Functionele werking, herstelbaarheid en eventuele toekomstige migraties zijn hiermee niet bewezen.**

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

## Tabel `settings`

**Bron:** screenshot van de volledige MySQL-uitvoer `SHOW CREATE TABLE settings;` (18 oktober 2026). De tabeldefinitie hieronder is overgenomen voor documentatiedoeleinden, zonder productiegegevens.

~~~sql
CREATE TABLE `settings` (
  `id` tinyint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtitle` varchar(220) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `introduction` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` date DEFAULT NULL,
  `event_time` varchar(90) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `location` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `address` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `contact_email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `practical_info` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
~~~

### Velden

| Kolom | Type | Betekenis |
|---|---|---|
| `id` | `tinyint unsigned`, NOT NULL, PK | Sleutel van instellingenrecord |
| `title` | `varchar(200)`, NOT NULL | Naam van de veiling |
| `subtitle` | `varchar(220)`, NOT NULL, default `''` | Ondertitel |
| `introduction` | `text`, NOT NULL | Introductietekst voor bezoekers |
| `event_date` | `date`, NULL toegestaan | Veilingdatum; nog niet gekende datum is mogelijk |
| `event_time` | `varchar(90)`, NOT NULL, default `''` | Tijdstip als vrije tekst |
| `location` | `varchar(190)`, NOT NULL, default `''` | Zaal/locatie |
| `address` | `varchar(300)`, NOT NULL, default `''` | Adres |
| `contact_email` | `varchar(190)`, NOT NULL, default `''` | Contactadres |
| `practical_info` | `text`, NOT NULL | Praktische informatie |
| `is_published` | `tinyint(1)`, NOT NULL, default `0` | Globale publicatieschakelaar |
| `updated_at` | `timestamp`, automatisch aangepast | Laatste wijzigingsmoment |

### Belangrijke technische eigenschappen

- **Single-recordconventie:** `private/bootstrap.php` gebruikt `SELECT * FROM settings WHERE id=1` en de beheerpagina `admin/instellingen.php` werkt `WHERE id=1` bij. De database **dwingt alleen uniciteit van `id` af**, niet dat er precies één record bestaat of dat het ID altijd 1 is. Een initieel instellingenrecord is dus noodzakelijk.
- **Publicatie:** de standaardwaarde `is_published=0` betekent verborgen; de PHP-applicatie beschouwt alleen waarde 1 als gepubliceerd. `tinyint(1)` is op zichzelf **geen database-CHECK op 0/1**.
- **Validatie:** MySQL laat voor `title` een lege string toe, hoewel het PHP-formulier een niet-lege titel vereist. Het e-mailadres wordt in PHP gecontroleerd; de DDL zelf definieert geen e-mailvalidatie.
- **Datum:** `event_date` is een echte MySQL-`date`, NULL toegestaan. `event_time` is bewust vrije tekst, geen MySQL-`time`.
- **Sleutels/indexen:** alleen `PRIMARY KEY (id)`; geen foreign keys in de getoonde tabel.
- **Engine/collation:** InnoDB, `utf8mb4_unicode_ci`.

**T-034 geslaagd (18-10-2026):** schema van `settings` is bevestigd met aangeleverde phpMyAdmin-uitvoer. Dit bewijst uitsluitend de tabelstructuur; het opslaan, herladen en publiceren van veilinginformatie (T-010 en T-006) is hiermee **niet** interactief getest.

## Tabel `admins`

**Bron:** screenshot van de volledige MySQL-uitvoer `SHOW CREATE TABLE admins;` (18 oktober 2026). Het productiegebonden `AUTO_INCREMENT=2` is bewust niet in de herbruikbare definitie overgenomen. **Er zijn geen e-mailadressen of wachtwoordhashes uit de database overgenomen.**

~~~sql
CREATE TABLE `admins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
~~~

### Velden en beveiliging

| Kolom | Type | Betekenis |
|---|---|---|
| `id` | `int unsigned`, PK, AUTO_INCREMENT | Interne beheerders-ID |
| `email` | `varchar(190)`, NOT NULL, UNIQUE | Uniek login-e-mailadres |
| `password_hash` | `varchar(255)`, NOT NULL | Opgeslagen wachtwoordhash; **geen plaintextwachtwoord bedoeld** |
| `created_at` | `timestamp`, default CURRENT_TIMESTAMP | Aanmaakdatum account |

- **Unieke e-mail:** `UNIQUE KEY email (email)` voorkomt dubbele accountadressen volgens de ingestelde `utf8mb4_unicode_ci`-collatie. Collatie beïnvloedt de tekenvergelijking; onder deze collatie is de vergelijking normaal niet hoofdlettergevoelig.
- **Authenticatie:** `catalogus-public/admin/login.php` vergelijkt het aangeboden wachtwoord met `password_verify()` tegen `password_hash`. De DDL zelf garandeert niet welk hash-algoritme gebruikt wordt; dat hoort bij de accountaanmaakcode en moet bij functionele beveiligingstests worden gecontroleerd.
- **Autorisatie:** er is geen `role`-, `permission`- of `is_active`-veld in deze tabel. Het huidige `require_auth()` in `private/bootstrap.php` controleert uitsluitend op een ingelogde `admin_id`; fijnmazige rollen/rechten worden in de geïnspecteerde code niet afgedwongen.
- **Bescherming van back-ups:** snapshots bevatten de tabel `admins`, inclusief wachtwoordhashes. ZIP-back-ups dus vertrouwelijk behandelen.
- **Geen referentiële constraints:** behalve `PRIMARY KEY` en `UNIQUE email` toont de DDL geen extra indexen of foreign keys. De tabel gebruikt InnoDB en utf8mb4_unicode_ci.

**T-035 geslaagd (18-10-2026):** structuur van `admins`, unieke e-mailindex en hashkolom bevestigd op aangeleverd phpMyAdmin-screenshot. **Geen functionele login-, aanmaak- of autorisatietests uitgevoerd.**

## Tabel `login_attempts`

**Bron:** volledige phpMyAdmin-uitvoer van `SHOW CREATE TABLE login_attempts;`, screenshot 18 oktober 2026. De tabel heeft geen productiegegevens in dit document.

~~~sql
CREATE TABLE `login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ip_hash` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts` (`ip_hash`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
~~~

### Velden en index

| Kolom | Type | Betekenis |
|---|---|---|
| `id` | `bigint unsigned`, PK, AUTO_INCREMENT | Interne sleutel voor een mislukte aanmeldpoging |
| `ip_hash` | `char(64)`, ASCII, NOT NULL | Hexadecimale SHA-256-hash van het IP-adres zoals de huidige PHP-code die opslaat |
| `attempted_at` | `timestamp`, NOT NULL, default CURRENT_TIMESTAMP | Tijdstip van de poging |

- **`idx_attempts(ip_hash,attempted_at)`** ondersteunt tellen van recente pogingen voor dezelfde IP-hash.
- Er zijn geen foreign keys. De tabel koppelt mislukte pogingen **niet** rechtstreeks aan een account of e-mailadres.
- In `catalogus-public/admin/login.php` wordt de IP-hash gevormd met `hash('sha256', REMOTE_ADDR)`. Bij een POST verwijdert de code pogingen ouder dan **24 uur** en weigert een poging als de database reeds **8** mislukkingen voor dezelfde hash uit de voorbije **15 minuten** telt. Bij succesvol inloggen worden de recente pogingen voor die IP-hash verwijderd.
- **Privacy:** een deterministische SHA-256-hash van een IP-adres is niet automatisch anoniem; het blijft mogelijk een persoonsgegeven. Beperk inzage, bewaar niet langer dan nodig en publiceer geen echte hashes in GitHub. Uit het schema volgt niet dat logopschoning daadwerkelijk periodiek loopt: de huidige opschoning gebeurt alleen tijdens een login-POST.
- **Beveiligingsgrens:** IP-gebaseerde rate limiting alleen is niet voldoende voor volledige bescherming tegen gedistribueerde aanvallen of gedeelde IP-adressen. De DDL toont infrastructuur, geen geslaagde aanvalstest.

**T-036 geslaagd (18-10-2026):** primair sleutelveld, `char(64)`-hashkolom, timestamp en samengestelde index bevestigd via screenshot. **T-037 (inlogbeperking en opschoning) staat open** en hoort in een geïsoleerde testomgeving.

### Nog te verifiëren

- Een duplicaat via de kaveleditor toont de gewenste foutmelding (T-003).
- CSV-upsert met bestaande lotnummers werkt zoals bedoeld (T-005).
- Gelijktijdige bewerkingen blijven correct en gebruikersvriendelijk.
- Alle vijf DDL's zijn opgenomen. Controleer bij toekomstige databasewijzigingen opnieuw of het gedocumenteerde schema overeenkomt met de server.
- Loginrate-limiting en opschoning functioneel testen in een geïsoleerde omgeving (T-037).
- Test van cascaderende verwijdering en opruimen van fysieke afbeeldingsbestanden in een testomgeving (T-033).
- Bij wijzigingen aan het schema moeten deze documentatie, `docs/REQUIREMENTS.md`, `docs/TESTPLAN.md` en `CHANGELOG.md` samen worden bijgewerkt.

> **Niet uitvoeren op productie:** deze DDL is voor documentatie/reproduceerbaarheid, niet bedoeld als migratiescript op een bestaande tabel. Gebruik voor toekomstige schemawijzigingen afzonderlijk gereviewde migraties en maak eerst een back-up.
