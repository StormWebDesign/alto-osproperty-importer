# AltoSync – Alto → OS Property Importer

Command-line PHP scripts that copy property listings from the **Alto (Vebra) v13 export API** into the **OS Property 6.8.0** component on the Huw Tudor / Tudor Estate Agents **Joomla 5** site (huwtudor.co.uk).

This README covers **how the system works now**. For the history of what was changed and why, see [DOCUMENTATION.md](DOCUMENTATION.md).

> Last reviewed against the code: **20 November 2026**

---

## Contents

1. [Quick start (returning after a break)](#1-quick-start-returning-after-a-break)
2. [Where things live](#2-where-things-live)
3. [How the pipeline works](#3-how-the-pipeline-works)
4. [Field mapping reference](#4-field-mapping-reference)
5. [Status ribbons (isSold) – read before touching StatusMapper](#5-status-ribbons-issold--read-before-touching-statusmapper)
6. [Database tables](#6-database-tables)
7. [Scripts reference](#7-scripts-reference)
8. [Common tasks](#8-common-tasks)
9. [Troubleshooting](#9-troubleshooting)
10. [Known issues and technical debt](#10-known-issues-and-technical-debt)
11. [Fresh install checklist](#11-fresh-install-checklist)

---

## 1. Quick start (returning after a break)

1. **Read the latest entries in [DOCUMENTATION.md](DOCUMENTATION.md)** to see what changed most recently.
2. **Check the logs on the server** (`logs/alto-sync.log` and `logs/alto-import.log`). Look for `ERROR`, `CRITICAL` and `WARNING`.
3. **To re-import one property**, set its row back to `processed = 0` and run `import.php`. See [§8](#8-common-tasks).
4. **Before changing how statuses or ribbons work**, read [§5](#5-status-ribbons-issold--read-before-touching-statusmapper). Two past bugs came from guessing what the `isSold` values mean.
5. **Never run `reset_all_data.php`, `clear_properties.php` or `cleanup_property_images.php` on the live site** unless you intend to wipe it.

---

## 2. Where things live

| What | Where |
|---|---|
| Scripts on the server | `/home/sites/24a/d/ddaaffa754/public_html/cli/alto-sync/` (StackCP hosting) |
| PHP binary on the server | `/usr/bin/php83` (PHP 8.3) |
| Local repo | `D:\Development\HuwTudor\alto-osproperty-importer` (branch `main`) |
| Database | MySQL on StackCP (`DB_HOST` in `config.php`), table prefix **`ix3gf_`** |
| Alto API | `https://webservices.vebra.com/export/TudorEAAPI/v13/` (datafeed `TudorEAAPI`, branch 8191) |
| Property images | `public_html/images/osproperty/properties/<osPropertyId>/` with `thumb/` and `medium/` subfolders |
| Saved full Alto XML | `xml/full_properties/<altoId>.xml` (overwritten on every import of that property) |
| Logs | `logs/alto-sync.log`, `logs/alto-import.log`, `logs/get_token.log` |
| Site's custom OS Property theme | `components/com_osproperty/templates/09092025154155default/` (not in this repo) |
| Joomla language overrides | `language/overrides/en-GB.override.ini` and `cy-GB.override.ini` (not in this repo) |

### Scheduling

The server's cron runs `sync.php` **every hour at about :05**; the timestamps in `alto-sync.log` show this. `import.php` also runs on a schedule, because properties get `processed = 1` without anyone running it by hand. The cron definitions are in **StackCP**, not in this repo, so check the exact schedule there.

### Configuration – `config.php` and `config.local.php`

`config.php` contains **no credentials**:

- **Database credentials** are read from **Joomla's own `configuration.php`**, in the `JConfig` class: `host` (which may be `host:port`), `db`, `user`, `password` and `dbprefix`. Joomla's root is assumed to be two folders up (`<joomla>/cli/alto-sync/`). If the Joomla database password changes, the importer picks it up automatically.
- **Alto API credentials** live in **`config.local.php`**. That file is in `.gitignore` and exists only on the server. To create it, copy `config.local.php.example` and fill it in. If the credentials are missing, `AltoApi` logs a `CRITICAL` error and makes no API calls.
- `config.local.php` is loaded **first**, so it can override any constant. Override `JOOMLA_ROOT`, or set all of `DB_*`, to run the scripts somewhere other than the Joomla server.

| Constant | Purpose |
|---|---|
| `JOOMLA_ROOT` | Joomla root folder (default: two levels above this folder) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PREFIX` | Joomla database connection (from `configuration.php`; `DB_PREFIX` = `ix3gf_`) |
| `ALTO_API_USERNAME`, `ALTO_API_PASSWORD` | Basic-auth credentials used to obtain an API token (from `config.local.php`) |
| `ALTO_API_BASE_URL`, `ALTO_API_DATAFEED_ID` | API base URL including datafeed ID and version |
| `TOKEN_FILE` | `tokens.txt` – cached API token as JSON `{"token": "...", "expiry": <unix time>}` |
| `LOGS_DIR`, `LOG_FILE_SYNC`, `LOG_FILE_IMPORT` | Log locations |
| `PROPERTY_IMAGE_UPLOAD_BASE_PATH` | Absolute path to `images/osproperty/properties/`. Uses the `DOC_ROOT` env variable if set, otherwise `$_SERVER['DOCUMENT_ROOT']`, otherwise `JOOMLA_ROOT` |
| `PROPERTY_IMAGE_DB_BASE_PATH_PREFIX` | `properties/` – prefix used for image paths |

> ⚠️ Earlier versions of `config.php` (and `config.working-backup.php` and `tokens.txt`) were **committed to git with live credentials**, and those credentials are still in the git history. See [§10](#10-known-issues-and-technical-debt).

---

## 3. How the pipeline works

```
           ┌────────────── sync.php (hourly) ──────────────┐
Alto API → │ 1. GET /branch          → ix3gf_alto_branches  │
           │ 2. GET <branch>/property → ix3gf_alto_properties (summary XML,
           │                             processed = 0 if new/changed) │
           └───────────────────────────────────────────────┘
                                   │
           ┌────────────── import.php ─────────────────────┐
           │ 1. Branch list → ix3gf_osrs_companies (insert only)       │
           │ 2. Up to 200 rows WHERE processed = 0:                    │
           │    GET full property XML (URL from summary)               │
           │    save to xml/full_properties/<altoId>.xml               │
           │    OsPropertyMapper::mapPropertyDetailsToDatabase()       │
           │    on success: processed = 1, images_imported = 1         │
           └───────────────────────────────────────────────┘
```

### 3.1 Authentication – `AltoApi.php`

- The first request goes to `<base>/branch` with **Basic auth (username:password)**. Alto returns a `Token:` response header.
- The token is cached in `tokens.txt` and treated as valid for **1 hour**; it's refreshed when fewer than 60 seconds remain.
- All later requests send `Authorization: Basic base64(token)`.
- On **401**, the token file is deleted and the call is retried with a new token. On **403**, the call fails, because the account lacks permission for that endpoint.
- `callApi()` accepts either `'branch'` or a full URL. Every other request uses URLs taken from the XML.

### 3.2 `sync.php` – find out what changed

1. **Checks and creates tables and columns** if they're missing: `alto_branches`, `alto_properties`, `osrs_xml_details`, and the extra columns `osrs_properties.alto_id`, `osrs_companies.alto_branch_id`, `osrs_companies.website` and `users.alto_negotiator_id`.
   **It does not create `alto_properties.images_imported`.** See [§11](#11-fresh-install-checklist).
2. **Fetches the branch list.** It stores the whole `<branches>` XML in `alto_branches` under the fixed ID `FULL_BRANCH_LIST_XML`, and resets `processed = 0` only if the XML has changed (SHA-256 comparison).
3. **Fetches the property list for each branch.** Each `<property>` summary is stored in `alto_properties`:
   - **New, or the summary XML changed:** stored with `processed = 0`, so it will be re-imported.
   - **Unchanged, but the property has no photos** (checked in the database and on disk): queued again with `processed = 0`.
   - **Unchanged:** only `last_synced` is updated.

`sync.php` never writes to the OS Property property tables.

### 3.3 `import.php` – write to OS Property

1. **Branches → companies.** This runs if `osrs_companies` is empty or `FULL_BRANCH_LIST_XML` is unprocessed, calling `OsPropertyMapper::mapBranchDetailsToDatabase()` for each branch. **Existing companies are never updated**, so manual edits in the admin are kept.
2. **Properties.** It takes up to **200** rows where `processed = 0`. For each one:
   - It fetches the full XML from the `<url>` in the summary.
   - It saves the XML to `xml/full_properties/<altoId>.xml`.
   - It calls `OsPropertyMapper::mapPropertyDetailsToDatabase()`.
   - **On success**, it sets `processed = 1` and `images_imported = 1`, and copies the XML into `osrs_xml_details`.
   - **If the mapping or the fetch fails**, it leaves `processed = 0`, so the property is retried on the next run.
   - **If the XML is malformed or has no URL**, it marks the row `processed = 1` and logs an error. It won't retry.

### 3.4 `OsPropertyMapper::mapPropertyDetailsToDatabase()` – the core

Each property is mapped inside **one database transaction**. The order is:

1. **Alto ID:** taken from `<property id="">`, falling back to `<prop_id>`.
2. **Dates:**
   - `modified` = `<lastchanged>` from the stored *summary* XML.
   - `created` = `<uploaded>`. If that is the placeholder `1900-01-01`, `lastchanged` is used instead.
   - `created` is only written on INSERT, so it never changes after the first import.
3. **Status:** `StatusMapper::map()` gives the `isSold` value. See [§5](#5-status-ribbons-issold--read-before-touching-statusmapper).
4. **Address:** `AddressMapper` looks up or creates the city (`osrs_cities`), county (stored in `osrs_states`) and country.
5. **Price:**
   - The price is stored as an integer.
   - **Price-reduced detection:** if the new price is lower than the stored one, the old price is kept in `price_original`. If the price is unchanged, `price_original` is carried forward. If the price went up, it's cleared.
6. **Property type:** `PropertyTypeMapper` uses a fixed table from Alto type text to OS Property `pro_type` ID.
7. **Category:** `CategoryMapper` uses the `<property database="">` attribute (see [§4](#4-field-mapping-reference)). If that fails, it uses `determineCategoryId()`, which works it out from the department, type, web_status and price wording.
8. **Brochure:** `BrochureMapper` puts the first `<file type="7">` PDF into `pro_pdf_file`.
9. **INSERT … ON DUPLICATE KEY UPDATE** into `osrs_properties`, keyed on `alto_id` (UNIQUE):
   - **New property:** the alias is first set to the Alto ID, then corrected to `{osPropertyId}-{slug}` once the new ID is known.
   - **Existing property:** `{osPropertyId}-{slug}` is written straight away.
   - This alias format is **required by OS Property's SEF router**. See DOCUMENTATION 2026-04-14.
10. **Category link:** `osrs_property_categories` is rewritten with the single category, which the listing template reads.
11. **`isSold` UPDATE:** it is always written, so stale ribbons are cleared.
12. **`AutoResetHelper`:** sets `pro_pdf_file1`–`9` to NULL. Then:
    - `PlansMapper` writes `<file type="2">` floor plans into `pro_pdf_file2`–`4`.
    - `EnergyRatingMapper` writes the first `<file type="9">` EPC into `pro_pdf_file5`.
13. **Images:** `ImagesMapper::importImages()`. See [§4.3](#43-images).
14. **Commit.** On any exception the transaction is rolled back and the property is retried on the next run.

**Fixed values:**
- `agent_id = 1` and `company_id = 0`. Alto negotiators are **not** mapped.
- `published = 1`, `approved = 1`, `access = 1`.
- `ref` = the Alto ID.

The importer **does not** write the Welsh columns (`pro_name_cy`, `pro_alias_cy`, `pro_full_desc_cy`, etc.).

---

## 4. Field mapping reference

### 4.1 Core fields

| Alto XML | OS Property `osrs_properties` column |
|---|---|
| `@id` / `prop_id` | `alto_id`, `ref` |
| `address/display` (fallback: name, street, locality) | `pro_name`; slug → `pro_alias` |
| address lines | `address`; `city` / `state` / `country` (lookup IDs); `postcode` |
| `summary` (fallback: first 300 chars of description) | `pro_small_desc` |
| `description` | `pro_full_desc` |
| `price/value` | `price` (integer) |
| `price/display_text` | `price_text` |
| `price/@currency_code` or `price/currency` (default GBP) | `curr` (looked up or created in `osrs_currencies`) |
| `bedrooms` / `bathrooms` / `receptions` | `bed_room` / `bath_room` / `rooms` |
| `latitude` / `longitude` | `lat_add` / `long_add` |
| `floor_area/total_floor_area_sqft` | `square_feet` |
| `land_area/total_land_area_sqft` | `lot_size` |
| `year_built` | `built_on` |
| `type` | `pro_type` (via `PropertyTypeMapper`) |
| `@database` | `category_id` (via `CategoryMapper`) |
| `web_status` (`@id` attribute, else text) + `custom_status` fallback | `isSold` (via `StatusMapper`) |
| `uploaded` / summary `lastchanged` | `created` / `modified` |

### 4.2 Categories (`CategoryMapper`)

| Alto `@database` | OS Property category ID | Name |
|---|---|---|
| 1 | 5 | For Sale – Residential |
| 2 | 6 | To Let – Residential |
| 3 | 7 | For Sale – Commercial |
| 4 | 8 | To Let – Commercial |

The site theme treats **5 and 7 as sales** and **6 and 8 as lettings** when choosing ribbon text.

### 4.3 Images

`ImagesMapper` collects the images from `<files><file>`, falling back to `<images><image>`. Two rules decide what counts as an image:
- Files of type `2` (floor plan), `5`, `6`, `7` (brochure) and `9` (EPC) are excluded.
- A file with an image extension counts as an image regardless of its type. Files with no extension are checked with an HTTP HEAD request.

**Sync rule (count-based):**
- If the database already has **at least as many photos as Alto**, nothing is downloaded.
- If it has fewer, only the extra images are downloaded, starting after the ones already there.
- Images keep Alto's order (`ordering` 0, 1, 2…). The first image is the main photo only because it comes first; the `$isDefault` argument is passed in but never used.

Downloaded files:
- are named `<pid>_<ordering 3 digits>_<sanitised original name>.<ext>`;
- have their extension corrected from the actual file content (Alto sometimes sends JPEG data in files named `.png`);
- get `thumb/` and `medium/` versions, sized from OS Property's configuration (defaults 170×110 and 600×370, quality 90);
- get a row in `osrs_photos`.

**Consequence:** if a photo is replaced or reordered in Alto without the number of photos increasing, the site won't pick it up. See [§8](#8-common-tasks).

### 4.4 PDFs and files

| Alto `<file type="">` | Column | Mapper |
|---|---|---|
| 7 (brochure PDF) | `pro_pdf_file` | `BrochureMapper` |
| 2 (floor plans) | `pro_pdf_file2`, `pro_pdf_file3`, `pro_pdf_file4` | `PlansMapper` |
| 9 (EPC – usually an image URL) | `pro_pdf_file5` | `EnergyRatingMapper` |

`pro_pdf_file1` and `pro_pdf_file6`–`9` are always cleared by `AutoResetHelper`. `BrochureMapper` writes any extra brochures into `pro_pdf_file1`–`9`, but those are wiped straight afterwards.

---

## 5. Status ribbons (isSold) – read before touching StatusMapper

The ribbon text on the website **does not** come from OS Property core or from the language overrides. It comes from the site's **custom theme**:
- `templates/09092025154155default/details.html.tpl.php` reads `category_id`;
- `listing.html.tpl.php` reads the lowest `category_id` in `osrs_property_categories`.

Both contain this hard-coded table:

| isSold | Lettings (cat 6, 8) | Sales (cat 5, 7) |
|---|---|---|
| 0 | no ribbon | no ribbon |
| 1 | Let | Sold |
| 2 | no ribbon | no ribbon |
| 3 | Let Agreed | Under Offer |
| 4 | no ribbon | Sold STC |
| 5 | Reserved | Reserved |
| 6 | no ribbon | no ribbon |
| 7 | Withdrawn | Withdrawn |

`StatusMapper::map()` converts Alto's `web_status` into these values:

| Alto web_status | Meaning | isSold | Shown as |
|---|---|---|---|
| 0, 100 | Available (sales / lettings) | 0 | – |
| **3** | **SSTC** (sales) | 3 | Under Offer *(client's preferred wording)* |
| **104** | **Let Agreed** (lettings) | 3 | Let Agreed |
| 1, 4 | Sold / Completed † | 1 | Sold |
| 2, 102 | Let † | 1 | Let |
| 7 | Sold STC † | 3 | Under Offer |
| 101 | Let Agreed † | 3 | Let Agreed |
| 103 | Withdrawn † | 7 | Withdrawn |
| anything else | – | 0 | – (logs a `WARNING`) |

**Bold** = confirmed from live data for this Alto account. † = never yet received; the meaning is assumed.

If `web_status` gives 0, `StatusMapper` falls back to matching `<custom_status>` text. That text is currently empty for every property.

**Other rules:**
- The `isSold` column is `NOT NULL`. Always write `0` for "no ribbon", never `null`.
- If the theme's table is changed, `StatusMapper` must be changed to match.
- **Print views, PDFs and alert emails use different wording.** They use stock `OSPHelper::returnMarketStatus()` with the language overrides: `OS_RENTED` (3) = "Let Agreed" and `OS_SALE_PENDING` (7) = "Sold STC".

### Other ribbons (also from the custom theme)

| Ribbon | Condition |
|---|---|
| New Instructions (`OS_JUSTADDED`) | `created` is within the last **7 days** |
| Price Reduced | `price_original > price` **and** `modified` is within the last 7 days |

---

## 6. Database tables

| Table | Owner | Written by | Notes |
|---|---|---|---|
| `ix3gf_alto_branches` | importer | sync, import | One row, `alto_branch_id = 'FULL_BRANCH_LIST_XML'` |
| `ix3gf_alto_properties` | importer | sync, import | One row per Alto property: summary XML, `processed`, `images_imported`, `last_synced` |
| `ix3gf_osrs_properties` | OS Property | import | Extra column `alto_id` (UNIQUE) added by the importer |
| `ix3gf_osrs_property_categories` | OS Property | import | Rewritten with one row per property |
| `ix3gf_osrs_photos` | OS Property | import | `pro_id`, `image`, `image_desc`, `ordering` |
| `ix3gf_osrs_companies` | OS Property | import | Extra columns `alto_branch_id` and `website`; insert only |
| `ix3gf_osrs_cities` / `_states` / `_countries` / `_currencies` | OS Property | import | Looked up by name; created if missing |
| `ix3gf_osrs_xml_details` | OS Property | import | Copy of the latest full XML per property and branch (`xml_id` = Alto ID) |
| `ix3gf_osrs_categories`, `_types` | OS Property | admin | **Not** created by the importer: category IDs 5–8 and the type IDs must already exist |
| `ix3gf_osrs_configuration` | OS Property | admin | Image sizes are read from here; `active_market_status = 1` and `market_status = 1,…,7` must be enabled |
| `ix3gf_users` | Joomla | (sync adds the column only) | `alto_negotiator_id` column, currently unused |

---

## 7. Scripts reference

### Production

| Script | Purpose |
|---|---|
| `sync.php` | Fetch branches and property summaries; queue changed properties (cron, hourly) |
| `import.php` | Map queued properties into OS Property (cron) |
| `get_token.php` | Manually get a fresh API token (not normally needed – `AltoApi` handles tokens itself) |

### Diagnostics (read-only)

| Script | Usage |
|---|---|
| `show_property_details.php` | `php show_property_details.php --id=<altoId>` – fetch and print a property's full XML live from Alto (branch 8191 is hard-coded). `debug_property.php` is an identical copy |
| `show_token.php` | Show cached token details – **currently broken**, see [§10](#10-known-issues-and-technical-debt) |
| `whoami.php` | Show the PHP user and whether the image folder exists and is writable |
| `test_address_mapper.php <xml>` | Run `AddressMapper` against a saved XML file (**writes** city/state/country lookups if missing) |
| `test_brochure_mapper.php <xml>` | Print the brochure URLs `BrochureMapper` would pick |
| `test_category_mapper.php` | Test matrix for `CategoryMapper` |

### Image maintenance

| Script | Purpose |
|---|---|
| `ResizeOsPropertyImages.php` | Regenerate `thumb/` and `medium/` for all properties |
| `BackfillOsPropertyImages.php` | Generate missing thumb/medium versions; optionally rebuild `osrs_photos` rows from originals on disk |
| `ImportPhotosFromFS.php` | `--pid=<id>` or `--all`, plus `--dry-run`, `--reorder` and `--prune`. Makes `osrs_photos` match the files on disk |

### ⚠️ Destructive – development / emergency only

| Script | What it does |
|---|---|
| `reset_all_data.php` | **Empties (TRUNCATE)** `osrs_properties`, `osrs_photos`, `osrs_property_categories`, `alto_properties` and `alto_branches`. No confirmation prompt |
| `clear_properties.php` | Empties the importer and OS Property tables, **deletes all property image folders**, then runs sync → import → `ImportPhotosFromFS --all --reorder --prune` → `BackfillOsPropertyImages --all`. Needs `--yes`. Options: `--no-fs-clear`, `--keep-xml`, `--keep-photos-table`, `--no-photo-scan`, `--no-backfill`, `--php=<bin>` |
| `cleanup_property_images.php` | Deletes everything in `images/osproperty/properties/` except `medium`, `panorama`, `thumb` and `index.html` |

> A full reset gives every property a **new OS Property ID**, which changes every property URL.

### Legacy / unused (kept for reference)

| File | Notes |
|---|---|
| `xml_to_sql_mapper.php` | The original mapper, superseded by `Mapper/OsPropertyMapper.php` |
| `import.working-backup.php`, `import.working-images-changing-order.php`, `config.working-backup.php` | Old snapshots |
| `Mapper/AttributesMapper.php`, `DescriptionMapper.php`, `EnergyMapper.php`, `GeolocationMapper.php` | Empty (0 bytes) |
| `Utils/CurlHelper.php` | Not used by anything |
| `cleanup_property_images` (no extension) | A stray log file written by `cleanup_property_images.php` |
| `price_snapshot_pre_reset.csv` | Price snapshot taken before the April 2026 reset |
| `StatusMapper::mapWebStatusToIsSold()`, `OsPropertyMapper::getOrCreateCategoryId()`, `OsPropertyMapper::getOrCreateAgentId()` | Unused methods. `getOrCreateCategoryId()` contains **wrong** status labels – don't copy from it |

---

## 8. Common tasks

All commands are run on the server from the scripts folder:

```bash
cd /home/sites/24a/d/ddaaffa754/public_html/cli/alto-sync
```

### Re-import a single property

```sql
-- find the Alto ID from the property URL's leading number (OS Property ID)
SELECT id, alto_id, pro_name, isSold, category_id FROM ix3gf_osrs_properties WHERE id = 87;

UPDATE ix3gf_alto_properties SET processed = 0 WHERE alto_property_id = '35014296';
```

```bash
/usr/bin/php83 import.php
grep 35014296 logs/alto-import.log | tail -40
```

### Re-import every property (keeps IDs, URLs and photos)

```sql
UPDATE ix3gf_alto_properties SET processed = 0;
```

Then run `import.php`. It processes 200 properties per run, so run it again until the log says `No new property XML found`.

### Refresh a property's photos (replaced or reordered in Alto)

Because image sync is count-based ([§4.3](#43-images)):

1. Delete the property's rows: `DELETE FROM ix3gf_osrs_photos WHERE pro_id = <pid>;`
2. Delete the files in `images/osproperty/properties/<pid>/` (including `thumb/` and `medium/`).
3. Set `processed = 0` for the property and run `import.php`.

### Check what Alto is sending for a property

- **Last imported version:** `xml/full_properties/<altoId>.xml`.
- **Live from the API:** `/usr/bin/php83 show_property_details.php --id=<altoId>`.
- **Status as it was read:** search `alto-import.log` for `STATUS RAW [Alto <altoId>]`.

### Useful SQL

```sql
-- Status distribution by category
SELECT p.isSold, c.category_name, COUNT(*) n
FROM ix3gf_osrs_properties p LEFT JOIN ix3gf_osrs_categories c ON c.id = p.category_id
GROUP BY p.isSold, c.category_name ORDER BY p.isSold;

-- Queue state
SELECT processed, images_imported, COUNT(*) FROM ix3gf_alto_properties GROUP BY processed, images_imported;

-- Properties with no photos
SELECT p.id, p.alto_id, p.pro_name FROM ix3gf_osrs_properties p
LEFT JOIN ix3gf_osrs_photos ph ON ph.pro_id = p.id WHERE ph.id IS NULL;
```

---

## 9. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| **Every** property page returns "404 Property is not available" | No Joomla menu item points to OS Property. Create one (DOCUMENTATION 2026-04-14) |
| A property URL loads the wrong property or returns 404 | `pro_alias` doesn't start with the OS Property ID. Re-import the property |
| Wrong status ribbon | Compare the `isSold` value and category against the table in [§5](#5-status-ribbons-issold--read-before-touching-statusmapper). Check `STATUS RAW` in the import log for the web_status Alto sent |
| "New Instructions" on an old listing | `created` is within 7 days. `created` comes from Alto `<uploaded>` and is only written on first INSERT, so a reset/re-create causes this |
| "Price Reduced" missing or stuck | Depends on `price_original > price` and `modified` within 7 days. `price_original` is only set when the importer sees the price go down |
| Grey placeholder image | No `thumb/` or `medium/` file. Run `ResizeOsPropertyImages.php`. Check the import log for `FAILED to write thumb` |
| `imagealphablending()` error when saving in the admin | Image extension doesn't match its content. The importer corrects this on download; for older files, rename them and update `osrs_photos.image` |
| Photo changed in Alto but not on the site | Count-based image sync – see [§8](#8-common-tasks) |
| Property removed from Alto is still on the site | Expected – the importer never unpublishes. Unpublish it manually in the OS Property admin |
| Floor plan or EPC link missing | Check the `<files>` block in the saved XML for `type="2"` / `type="9"` |
| Sync log shows 401 errors | Token problem. Delete `tokens.txt`; it will be recreated on the next run. 401 on the *first* request means the username/password are wrong |
| Sync log shows 403 errors | The Alto account lacks permission for that branch or endpoint – contact Alto |
| `import.php` fails with `Unknown column 'images_imported'` | The column is missing – see [§11](#11-fresh-install-checklist) |
| Nothing is being imported | Check that `processed = 0` rows exist (sync only queues new or changed summaries) and that the cron is running |

---

## 10. Known issues and technical debt

Found during the November 2026 review. **None of these has been fixed.**

1. **Credentials in git history.** Since 2026-11-20, `config.php` holds no secrets: the database credentials come from Joomla and the Alto credentials from an untracked `config.local.php`. But the old database and Alto passwords are still in the git history, in `config.php`, `config.working-backup.php` and `tokens.txt`, and `config.working-backup.php` still contains them. Recommendation:
   - Delete `config.working-backup.php`.
   - Change the database and Alto passwords.
   - Remove the files from git history if the repo is ever shared.
2. **SSL verification disabled** in `AltoApi.php` (`CURLOPT_SSL_VERIFYPEER/HOST = false`, marked "for debugging only").
3. **The token helper scripts don't match the token file format.**
   - `AltoApi` writes `{"token", "expiry"}`.
   - `get_token.php` reads `expires_at` / `expires_in`, so it always deletes the token and requests a new one.
   - `show_token.php` reads `access_token`, so it always reports "invalid".
4. **Removed listings stay live.** Properties that drop out of the Alto feed are never unpublished or deleted.
5. **Image sync is count-based.** Replaced, reordered or deleted photos in Alto aren't reflected (see [§4.3](#43-images)).
   - The `images_imported` flag is set and passed to `ImagesMapper`, but it is only logged; it doesn't affect anything.
6. **The `images_imported` column isn't created automatically.** `sync.php` doesn't add it, but `import.php` needs it.
7. **Welsh content isn't imported.** None of the `*_cy` columns are written.
8. **Negotiators aren't mapped.** Every property gets `agent_id = 1` and `company_id = 0`.
9. **Logs are never rotated.** `alto-sync.log` was already 78 MB in August 2026.
10. **Extra brochure PDFs are discarded.** `BrochureMapper` fills `pro_pdf_file1`–`9`, but `AutoResetHelper` clears them straight away.
11. **The `custom_status` text match is loose.** `str_contains('let')` also matches "Completed" or "To Let". It's harmless while the field is empty.
12. **An edge case can stop the import run.** If the property INSERT fails, `$propertyOsId` is null, and `AutoResetHelper::resetExtraFilesObject(int …)` throws a `TypeError`. That isn't caught by `catch (\Exception)`, so the whole run stops.
13. **Legacy and dead files** clutter the folder (see [§7](#7-scripts-reference)).

---

## 11. Fresh install checklist

1. Install OS Property and the site's custom theme `09092025154155default`, and select that theme in the OS Property configuration.
2. Create OS Property categories with **IDs 5–8** (see [§4.2](#42-categories-categorymapper)) and the property types used by `PropertyTypeMapper`.
3. Enable market status: `active_market_status = 1` and all statuses 1–7 ticked.
4. **Create a Joomla menu item for OS Property before importing.** Without it, every property URL returns 404.
5. Upload this folder to `public_html/cli/alto-sync/`. Copy `config.local.php.example` to `config.local.php` and enter the Alto API username and password. The database credentials are read from Joomla's `configuration.php` automatically.
6. Run `php sync.php` once. It creates the tracking tables and extra columns.
7. Add the image flag column, which `sync.php` doesn't create:
   ```sql
   ALTER TABLE `ix3gf_alto_properties`
     ADD COLUMN `images_imported` TINYINT(1) NOT NULL DEFAULT 0 AFTER `processed`;
   ```
8. Run `php import.php` repeatedly until everything has been imported (200 properties per run).
9. Run `php whoami.php` to confirm the images folder is writable.
10. Set up cron jobs for `sync.php` (hourly) and `import.php` (shortly after sync) using `/usr/bin/php83`.
