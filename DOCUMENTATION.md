# AltoSync – OS Property Importer: Technical Documentation

This document is the **change log**: what was broken or added, why, and how it was fixed. It is ordered newest first within each section.

For **how the system works now** (architecture, field mappings, the `isSold` ribbon table, scripts, common tasks and known issues), see [README.md](README.md).

> **Note on earlier entries:** some statements in older entries were later found to be wrong. They are left unchanged as a record. The corrections are listed under [Changes → 2026-11-20 – Documentation review](#2026-11-20-1500--documentation-review-and-corrections-to-earlier-entries).

---

## How To Update This Document

> **This section is a prompt for Claude to follow when updating this file.**
>
> When a bug is fixed, a feature is added, or a significant change is made to this project:
>
> 1. Add a new entry under the relevant section below (Bug Fixes, Features, or Changes).
> 2. Use the heading format: `### [YYYY-MM-DD HH:MM] – Short title`
> 3. Use today's actual date and approximate time of the fix.
> 4. Include the following in each entry:
>    - **What was broken / What was needed** — describe the symptom or requirement
>    - **Root cause / Reason** — explain why it was happening or why it was needed
>    - **Files changed** — list every file that was modified
>    - **What was changed** — describe the code change clearly enough that a developer can understand it without reading the diff
>    - **How to verify** — describe how to confirm the fix or feature is working
> 5. Do not delete or edit previous entries — this is a historical log. If an earlier entry turns out to be wrong, add a correction under **Changes** and say which entry it corrects.
> 6. Keep language clear and non-technical where possible, as this document may be read by the client.
> 7. Put new entries at the **top** of their section (newest first).
> 8. If the change affects how the system works (pipeline, mappings, status/ribbon values, scripts, setup steps or known issues), update [README.md](README.md) as well and change its "Last reviewed" date.
> 9. Before changing anything to do with status ribbons, read README §5. The ribbon wording comes from the site's custom OS Property theme, not from OS Property core.

---

## Bug Fixes

---

### [2026-11-20 ~13:00] – "Let Agreed" lettings showing a "Withdrawn" banner on the frontend

#### What was broken
Lettings properties set to "Let Agreed" in Alto were displaying a "Withdrawn" ribbon and status banner on the website (e.g. Tyn Y Mur Estate, Morfa Nefyn — OS Property ID 87, Alto ID 35014296).

#### Root cause
The ribbon wording on this site does not come from OS Property's standard market status labels. It comes from the site's custom OS Property theme (`components/com_osproperty/templates/09092025154155default/`), whose `details.html.tpl.php` and `listing.html.tpl.php` contain their own `isSold` → label table. That table depends on whether the property's category is a letting (categories 6 and 8) or a sale (categories 5 and 7):

| isSold | Lettings (cat 6, 8) | Sales (cat 5, 7) |
|---|---|---|
| 0 | no ribbon | no ribbon |
| 1 | Let | Sold |
| 3 | Let Agreed | Under Offer |
| 4 | no ribbon | Sold STC |
| 5 | Reserved | Reserved |
| 7 | Withdrawn | Withdrawn |

(2 and 6 show no ribbon.)

The `isSold` scale described in the two earlier entries below (0 = New Instructions … 7 = Let) was incorrect for this site. The importer had been written against that scale:

- Alto sends web_status `104` for lettings properties that are **Let Agreed**. The importer mapped `104` to `isSold = 7`, which this theme displays as "Withdrawn".
- Active listings were mapped to `isSold = null`. The `isSold` column is defined `NOT NULL DEFAULT 0`, so MySQL was silently storing `0` anyway. This worked, but only by accident.

#### Files changed
- [Mapper/StatusMapper.php](Mapper/StatusMapper.php)
- [Mapper/OsPropertyMapper.php](Mapper/OsPropertyMapper.php) (comment only)

#### What was changed

**`Mapper/StatusMapper.php` — `map()` method**

The numeric web_status mapping now uses the custom theme's scale above (documented in the method's docblock):

| Alto web_status | Meaning | isSold before | isSold now | Site shows |
|---|---|---|---|---|
| 0 / 100 | Available | null | 0 | no ribbon |
| 3 | SSTC (sales) | 3 | 3 (unchanged) | Under Offer — client confirmed this wording is wanted |
| **104** | **Let Agreed (lettings)** | **7** | **3** | **Let Agreed** |
| 1, 4 | Sold / Completed* | 4 | 1 | Sold |
| 2, 102 | Let* | 7 | 1 | Let |
| 7 | Sold STC* | 3 | 3 (unchanged) | Under Offer |
| 101 | Let Agreed* | 6 | 3 | Let Agreed |
| 103 | Withdrawn* | 4 | 7 | Withdrawn |

\* These codes have never been received from this Alto account. Only 0, 3, 100 and 104 appear in the live data. They keep the meaning the previous code assumed, mapped onto the correct `isSold` values. Any numeric code not in the list now writes a `WARNING` to `logs/alto-import.log` and defaults to no ribbon.

The text-based status matching (used for the `<custom_status>` fallback) already produced values consistent with the theme and was not changed.

**`Mapper/OsPropertyMapper.php`**

Comment updated to say active listings write `0` rather than `NULL`. No code change.

#### How to verify
1. Reset the affected property and re-import:
   ```sql
   UPDATE ix3gf_alto_properties SET processed = 0 WHERE alto_property_id = '35014296';
   ```
   then run `php import.php`.
2. Check `logs/alto-import.log` — should show `web_status='104' → isSold=3 ('Let Agreed')`.
3. The property page and the lettings listing should show a "Let Agreed" ribbon instead of "Withdrawn".
4. Confirm no property is left showing "Withdrawn" incorrectly:
   ```sql
   SELECT id, alto_id, pro_name, category_id, isSold
   FROM ix3gf_osrs_properties
   WHERE isSold = 7;
   ```
   This should return no rows unless Alto genuinely marks a property as withdrawn.
5. Sales properties that are SSTC in Alto should still show "Under Offer".

#### Notes
- Print views, PDFs and alert emails use OS Property's standard labels (with the site's language overrides) rather than the custom theme. The same `isSold` value can therefore show different wording there, e.g. `3` = "Let Agreed" and `4` = "Off market".
- If the custom theme's status table is ever changed, `StatusMapper::map()` must be updated to match.

---

### [2026-08-17 ~14:00] – SSTC / Under Offer properties not showing correct status on the frontend

#### What was broken
Properties marked as SSTC (Sold Subject to Contract) in Alto were not displaying any sold/under-offer status on the OS Property frontend. They appeared as normal active listings with no ribbon.

Additionally, four lettings properties with a "Completed" tenancy were displaying a "Sold" ribbon, which is incorrect for a lettings listing.

#### Root cause
Three separate issues combined:

1. **Wrong web_status mapping for SSTC**: For this Alto account, web_status `3` means SSTC/Sold Subject to Contract for sales listings. The `StatusMapper` was mapping web_status `3` to `isSold = 6` ("Let Agreed"), which was incorrect. The original developer's older method (`mapWebStatusToIsSold`) had correctly mapped it to isSold=3 (Sold STC), but this was broken when the StatusMapper was rewritten in April 2026. Lettings "Let Agreed" arrives as web_status `101` (already handled separately), so there is no conflict.

2. **Active listings writing `isSold = 0` instead of `null`**: The StatusMapper was returning `isSold = 0` for active for-sale (web_status 0) and active to-let (web_status 100) properties. In OS Property, isSold=0 is the "New Instructions" state. The correct value for an active listing with no status overlay is `null` (writes NULL to the database, shows no ribbon). The isSold update guard was also still using `isset($propertyIsSold)`, which silently skips writing NULL to the database — meaning a property that moved from SSTC back to active would keep its old ribbon indefinitely.

3. **Wrong mapping for Lettings Completed (web_status 104)**: The StatusMapper mapped web_status `104` (Lettings Completed) to `isSold = 4` ("Sold"), causing lettings properties whose tenancy had ended to display a "Sold" ribbon. The correct label for a completed lettings property is `isSold = 7` ("Let").

#### Files changed
- [Mapper/StatusMapper.php](Mapper/StatusMapper.php)
- [Mapper/OsPropertyMapper.php](Mapper/OsPropertyMapper.php)

#### What was changed

**`Mapper/StatusMapper.php`**

- `case 3` changed from `isSold = 6` ("Let Agreed") to `isSold = 3` ("Sold STC") — this is the correct mapping for this Alto account where web_status 3 = SSTC for sales properties.
- `case 0` changed from `isSold = 0` to `isSold = null` — active for-sale listings now write NULL to the database, showing no status ribbon.
- `case 100` changed from `isSold = 0` to `isSold = null` — same fix for active to-let listings.
- `case 104` changed from `isSold = 4` ("Completed") to `isSold = 7` ("Let") — completed lettings tenancies now correctly show as "Let" rather than "Sold".

**`Mapper/OsPropertyMapper.php`**

- The `custom_status` fallback block (which handles Alto accounts that send SSTC via the `<custom_status>` element) had its trigger condition updated from `$propertyIsSold === 0` to `($propertyIsSold === null || $propertyIsSold === 0)`, so it continues to work now that active listings return null from StatusMapper.
- The isSold UPDATE guard was changed from `isset($propertyIsSold)` to `$status !== null`. `isset(null)` returns false, which meant NULL was never written to the database for active listings. With the guard changed, the UPDATE always runs, correctly clearing a stale ribbon when a property reverts from SSTC back to active.

#### How to verify
1. Find a property marked SSTC in Alto. Reset it (`processed = 0`) and re-run `import.php`.
2. Check the log — should show `web_status='3' → isSold=3 ('Sold STC')` and `Set isSold=3`.
3. The property should display the "Under Offer" / Sold STC ribbon on the frontend.
4. Run the full status distribution query — should show no `isSold = 4` and no cross-category mismatches:
   ```sql
   SELECT isSold, COUNT(*) FROM ix3gf_osrs_properties GROUP BY isSold ORDER BY isSold;
   SELECT o.alto_id, o.pro_name, o.category_id, o.isSold
   FROM ix3gf_osrs_properties o
   WHERE (o.category_id = 5 AND o.isSold IN (6, 7))
      OR (o.category_id = 6 AND o.isSold IN (3, 4));
   ```

---

### [2026-04-17 ~14:00] – Price-reduced properties showing "New Instructions" ribbon on the frontend

#### What was broken
Properties that had recently had their price reduced in Alto were displaying a "New Instructions" status ribbon on the OS Property frontend detail page, making them appear as newly listed properties.

#### Root cause
Two separate issues combined to cause this:

1. **OS Property's ribbon logic** (in the frontend template `details.html.tpl.php`) shows a "New Instructions" ribbon for any property whose `created` date is within the last 3 days.

2. **Our importer was setting `created` from Alto's `lastchanged` timestamp** — the same timestamp used for `modified`. When a price reduction happens in Alto, `lastchanged` updates to today. Because `created` was derived from `lastchanged`, the property appeared freshly created in OS Property's eyes, triggering the ribbon.

The `isSold` field in OS Property was also mapped incorrectly throughout `StatusMapper`. The assumption was that `isSold = 0` meant "Available", but in OS Property `isSold = 0` is actually "New Instructions". The full correct scale is: 0 = New Instructions, 1 = For Sale, 2 = Under Offer, 3 = Sold STC, 4 = Sold, 5 = To Let, 6 = Let Agreed, 7 = Let.

#### Files changed
- [Mapper/OsPropertyMapper.php](Mapper/OsPropertyMapper.php)
- [Mapper/StatusMapper.php](Mapper/StatusMapper.php)

#### What was changed

**`Mapper/OsPropertyMapper.php`**

`$createdDate` is now derived from `$propertyXmlObject->uploaded` (the date the property was first listed in Alto) rather than `lastchanged`. `$modifiedDate` continues to use `lastchanged`. A guard treats the Alto sentinel date `1900-01-01` as absent and falls back to `lastchanged` in that case. Since `created` is not in the `ON DUPLICATE KEY UPDATE` clause, existing properties are unaffected; only properties not yet in the database will benefit from the correct date on their first import.

The `isSold` update guard was also changed from `isset($propertyIsSold)` to `$status !== null`. The old guard silently skipped writing `NULL` to the database (because `isset(null)` is false), which meant active listings could never have their stale `isSold` value cleared.

**`Mapper/StatusMapper.php`**

All `isSold` values were corrected to match OS Property's actual scale. Active for-sale (web_status 0) and active to-let (web_status 100) listings now return `isSold = null`, which writes `NULL` to the database and shows no ribbon. Other statuses were remapped: Sold → 4, Sold STC → 3, Let Agreed → 6, Let → 7, To Let → 5, Completed/Withdrawn → 4.

#### How to verify
1. Find a property that recently had a price reduction and was showing "New Instructions".
2. Reset it (`processed = 0`) and re-run `import.php`.
3. On the frontend detail page, the "New Instructions" ribbon should no longer appear.
4. Check `logs/alto-import.log` — the `created date` log line should show the original upload date (e.g. `2023-06-27`), not today's date.

---

### [2026-04-14 17:00] – English property detail pages returning 404 or loading wrong property data

#### What was broken
On the English language version of the site, clicking a property in the listing view either returned "404 Property is not available" or loaded a completely different property's details. The Welsh version of the same properties always worked correctly. Manually removing the Alto property ID from the English URL also made the page load correctly.

#### Root cause
The `pro_alias` field stored in `#__osrs_properties` was being generated as `{altoId}-{propertyNameSlug}` (e.g. `34530164-1-brynhudfa-clynnog-fawr`). OS Property's SEF router uses this field directly as the URL segment when building property detail links. When parsing the URL back, it extracts the leading numeric portion as the OS Property ID and performs a database lookup. Since the Alto property ID (an 8-digit external reference) is never a valid OS Property ID (a small auto-increment integer), the lookup failed — resulting in either a 404 or a fallback that returned the wrong property.

The Welsh version was unaffected because the Welsh alias field (`pro_alias_cy`) was stored as a plain slug with no numeric prefix (e.g. `1-brynhudfa-clynnog-fawr`), which OS Property's router resolved correctly via a full alias match.

#### Files changed
- [Mapper/OsPropertyMapper.php](Mapper/OsPropertyMapper.php)

#### What was changed
The alias generation logic in `mapPropertyDetailsToDatabase()` was rewritten to use the **OS Property ID** (not the Alto ID) as the numeric prefix.

For **existing properties** (already in the DB), the OS Property ID is looked up before the INSERT/UPDATE, so the correct alias (e.g. `37-cae-hendy-llanbedrog`) is written in one step.

For **new properties**, the Alto ID is used as a temporary placeholder alias during the INSERT (it is unique, so no collision can occur). Immediately after MySQL assigns the auto-increment OS Property ID, a second UPDATE statement corrects the alias to `{osPropertyId}-{slug}` (e.g. `46-1-brynhudfa-clynnog-fawr`).

This ensures the URL segment OS Property generates in the listing always matches what its router can resolve back to the correct property record.

A full reset and re-import is required after deploying this change so all existing property records get aliases in the correct format.

#### How to verify
1. After a full reset and re-import, click any property in the English listing view.
2. The URL should be in the format `/en/.../46-1-brynhudfa-clynnog-fawr` (OS Property ID as the numeric prefix).
3. The correct property detail page should load.
4. Repeat for Welsh — URLs should remain in the plain slug format and continue to work.
5. Properties that previously returned 404 or loaded wrong data should now load correctly on both language versions.

---

### [2026-04-14 15:00] – imagealphablending error in OS Property backend after Alto image replacement

#### What was broken
When a client replaced a property image in Alto (downloading the PNG, re-saving as JPG in Paint, then re-uploading), the OS Property backend threw a fatal error when trying to save the property:

```
imagealphablending(): Argument #1 ($image) must be of type GdImage, bool given
```

The property also returned a "Property is not available" 404 on the frontend detail page when accessed via SEF URL.

#### Root cause
Alto kept the original `.png` filename even after the client re-uploaded a JPEG file. Our importer derived the saved filename extension from the Alto filename (`.png`), so the downloaded file was stored on disk as `.png` even though its content was JPEG.

OS Property's own backend image processing uses extension-based loading: it calls `imagecreatefrompng()` on any `.png` file. Since the file actually contained JPEG data, `imagecreatefrompng()` returned `false`. OS Property then passed that `false` directly into `imagealphablending()`, causing the fatal error.

The "Property is not available" 404 was a separate, unrelated issue — the Joomla menu item for OS Property had not been configured with a default frontend URL, so Joomla's SEF router could not resolve any property detail page URLs.

#### Files changed
- [Mapper/ImagesMapper.php](Mapper/ImagesMapper.php)

#### What was changed

**`Mapper/ImagesMapper.php` — `downloadAndMapImage` method**

Added an extension-correction step that runs immediately after a file is downloaded. It uses `getimagesize()` to detect the actual content type of the file and compares it against the extension derived from the Alto filename. If they don't match (e.g. file is JPEG but named `.png`), the file is renamed to the correct extension (`.jpg`) before the DB record is written.

This ensures the filename on disk and in the database always reflects the actual image format, so OS Property's own extension-based image loading works correctly.

The correction also re-checks the database for an existing record under the corrected filename before inserting, to avoid duplicates.

#### How to verify
1. In Alto, replace a property image with a file that has a mismatched extension (e.g. JPEG content with a `.png` name).
2. Reset the property (`processed = 0`, delete photos from DB and disk) and re-run `import.php`.
3. Check `logs/alto-import.log` for a line like:
   ```
   Extension corrected: 46_000_15.png → 46_000_15.jpg (file content is jpg, extension was png)
   ```
4. Confirm the file on disk has the `.jpg` extension.
5. Editing and saving the property in the OS Property backend should complete without error.

---

### [2026-04-14 15:30] – Property detail pages returning 404 on the frontend

#### What was broken
All property detail page URLs returned "404 Property is not available" (thrown by `components/com_osproperty/classes/listing.php`) even though the properties were correctly imported, published, and approved in OS Property.

#### Root cause
No Joomla menu item had been created pointing to the OS Property component. Joomla's SEF router requires at least one menu item for a component in order to build and resolve frontend URLs for it. Without one, the router has no basis to construct or parse property detail page URLs, so every property link 404s regardless of the data being correct.

#### Files changed
None — this was a Joomla site configuration issue, not a code change.

#### What was changed
A default frontend menu item was added in the Joomla admin pointing to the OS Property component. This gives the SEF router the anchor it needs to resolve property detail URLs.

#### How to verify
Property detail pages load correctly from both the "All Properties" category listing and direct URLs.

> **Note for future installs:** This menu item must be in place before any properties are imported. If all properties return "404 Property is not available" on a fresh install, check this before debugging data or code.

---

### [2026-04-14 12:00] – PNG property images not displaying on site

#### What was broken
Properties where Alto had uploaded photos with `.png` filenames were showing a grey placeholder image on the site instead of the actual property photo. The images were either not being imported at all, or were imported into the database but had no thumbnail or medium-size variants generated, causing OS Property to display nothing.

#### Root cause
Alto's S3 storage saves property photos using `.png` file extensions and names (e.g. `15.png`, `17.png`) even though the actual file content is JPEG image data. The importer was trusting the filename/extension rather than reading the actual file content, which caused failures at two points:

1. **Image collection** — the `isProbablyImage` function required both a recognised Alto file type attribute *and* an image extension to accept a file. If a file had a clear `.png` extension but an unrecognised `type` attribute, it was silently skipped and never downloaded.

2. **Thumbnail generation** — the `resizeOne` function used the file extension (`.png`) to decide which PHP GD function to call. Because the file contained JPEG data, `imagecreatefrompng()` failed silently, so no `thumb/` or `medium/` variants were written. OS Property requires these variants to display images on the frontend.

A third issue prevented the standalone thumbnail regeneration tool (`ResizeOsPropertyImages.php`) from running at all in CLI context: it used `$_SERVER['DOCUMENT_ROOT']` to build the image directory path, which is empty when running from the command line, causing an immediate "Base dir not found" failure.

#### Files changed
- [Mapper/ImagesMapper.php](Mapper/ImagesMapper.php)
- [ResizeOsPropertyImages.php](ResizeOsPropertyImages.php)

#### What was changed

**`Mapper/ImagesMapper.php` — `isProbablyImage` method**

The old logic:
```php
if ($typeSuggestsImage && $hasImgExt) { return true; }
if ($typeSuggestsImage) { /* HEAD request fallback */ }
return false;
```
Required *both* conditions to be true. A file with a clear image extension but an unrecognised Alto type was rejected.

Replaced with:
```php
// Explicitly block known non-image types (floor plans = 2, EPCs = 9, etc.)
if (in_array($typeAttr, ['2', '5', '6', '7', '9'], true)) { return false; }
// Clear image extension = it's an image, regardless of type attribute
if ($hasImgExt) { return true; }
// No extension — fall back to HEAD request content-type detection
```

**`Mapper/ImagesMapper.php` — `resizeOne` method**

The old code used `pathinfo($src, PATHINFO_EXTENSION)` (the filename extension) to decide which GD loader to call:
```php
$ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
switch ($ext) {
    case 'png': $im = imagecreatefrompng($src); break;
    ...
}
```

Replaced with `getimagesize()` to detect the actual image format from the file's binary content:
```php
[$w, $h, $imgType] = @getimagesize($src);
switch ($imgType) {
    case IMAGETYPE_JPEG: $im = imagecreatefromjpeg($src); break;
    case IMAGETYPE_PNG:  $im = imagecreatefrompng($src);  break;
    case IMAGETYPE_GIF:  $im = imagecreatefromgif($src);  break;
}
```
The same content-detection approach was applied to the output (save) step, so a JPEG-content file named `.png` is written correctly as JPEG data in the `thumb/` and `medium/` directories.

**`ResizeOsPropertyImages.php` — `resizeOne` method**

Same extension-vs-content fix as above applied to this standalone script's own copy of `resizeOne`.

**`ResizeOsPropertyImages.php` — base directory path**

Changed from:
```php
$this->baseDir = rtrim($_SERVER['DOCUMENT_ROOT'] . '/images/osproperty/properties', '/');
```
To:
```php
$this->baseDir = rtrim(\PROPERTY_IMAGE_UPLOAD_BASE_PATH, '/');
```
`PROPERTY_IMAGE_UPLOAD_BASE_PATH` is defined in `config.php` and resolves correctly in both CLI and web contexts.

#### How to verify
1. Run `php import.php` — check `logs/alto-import.log` for no `FAILED to write thumb` or `FAILED to write medium` errors against PNG-named files.
2. Check the live site — properties that previously showed a grey placeholder should now display their images.
3. To regenerate thumbnails for any existing PNG properties without a full re-import: `php ResizeOsPropertyImages.php`

---

## Features

---

### [2026-04-17 ~14:00] – "Price Reduced" ribbon support (documented retrospectively, 2026-11-20)

#### What was needed
The site's theme shows a "Price Reduced" ribbon on properties whose price has recently dropped. The importer needed to give the theme the information to do that. This change was made in the same commit as the "New Instructions" fix below (git `b516fbf`) but was not written up at the time.

#### Reason
OS Property can only tell that a price was reduced if the old price is stored in `price_original`. Alto only sends the current price, so the importer has to notice the drop itself.

#### Files changed
- [Mapper/OsPropertyMapper.php](Mapper/OsPropertyMapper.php)

#### What was changed
Before writing a property that already exists, the importer reads its stored `price` and `price_original`:
- **New price lower than stored price:** the stored price is saved into `price_original`.
- **Price unchanged:** the existing `price_original` is kept.
- **Price went up:** `price_original` is cleared, which removes the ribbon.

The custom theme (`details.html.tpl.php`) shows "Price Reduced" when `price_original > price` **and** `modified` is within the last 7 days. "New Instructions" takes priority if `created` is also within the last 7 days.

#### How to verify
1. Reduce a property's price in Alto and wait for the next sync/import, or reset it to `processed = 0` and run `import.php`.
2. The import log should show `Price reduction detected: £X → £Y. Storing original.`
3. The property should show "Price Reduced" on the frontend for 7 days.

---

## Changes

---

### [2026-11-20 ~16:00] – Credentials removed from config.php

#### What was needed
`config.php` contained the live Joomla database password and the Alto API username and password, and it was committed to git.

#### Reason
The scripts run from Joomla's `/cli/alto-sync/` folder, so the database credentials can be read from Joomla's own `configuration.php` and don't need to be duplicated. The Alto credentials have no equivalent in Joomla, so they were moved to a file that isn't committed.

#### Files changed
- [config.php](config.php)
- [config.local.php.example](config.local.php.example) (new)
- [AltoApi.php](AltoApi.php)
- [.gitignore](.gitignore)

#### What was changed
- **`config.php`:**
  - Loads `config.local.php` first, if it exists, so it can override any setting.
  - Then reads `host` (including `host:port`), `db`, `user`, `password` and `dbprefix` from Joomla's `configuration.php` (`JConfig`), two folders up, and defines the same `DB_*` constants as before. No other script needed to change.
  - If Joomla's configuration can't be found, it stops with a clear message.
  - The Alto username and password default to empty unless `config.local.php` defines them.
- **`config.local.php.example`:** a template for the server-only `config.local.php`, which holds the Alto credentials and optional overrides.
- **`AltoApi.php`:** if the Alto credentials are empty, it logs `CRITICAL: Alto API credentials are not set…` and makes no API call. Previously it would have sent blank credentials.
- **`.gitignore`:** `config.local.php` added.

#### How to verify
1. On the server, create `cli/alto-sync/config.local.php` from the example, with the Alto username and password.
2. Upload the new `config.php` and `AltoApi.php`.
3. Run `/usr/bin/php83 sync.php`. `logs/alto-sync.log` should show `Database connection established` and a successful branch list call.
4. Run `/usr/bin/php83 import.php` and check `logs/alto-import.log` for errors.

> **Important:** upload `config.local.php` **before** or together with the new `config.php`. Otherwise the next scheduled sync will log the missing-credentials error and do nothing until the file is in place. No data is lost.

---

### [2026-11-20 15:00] – Documentation review and corrections to earlier entries

#### What was needed
A full review of the code so that someone returning to the project can get started quickly. The README was last updated in November 2025 and no longer matched the code.

#### Files changed
- [README.md](README.md) — completely rewritten
- [DOCUMENTATION.md](DOCUMENTATION.md) — introduction, update instructions, the retrospective "Price Reduced" entry, and this entry

No code was changed.

#### What was changed
**README.md** now covers:
- server locations and scheduling;
- the sync → import pipeline step by step;
- field, category, image and PDF mappings;
- the `isSold` ribbon table taken from the site's custom theme;
- every script, including which are destructive and which are legacy;
- common tasks with SQL;
- troubleshooting;
- a list of known issues found during the review;
- a fresh-install checklist.

**Corrections to earlier entries.** These are left unchanged above, but are wrong or out of date:

| Entry | Statement | Correction |
|---|---|---|
| 2026-04-17 (New Instructions) and 2026-08-17 (SSTC) | `isSold` scale "0 = New Instructions, 1 = For Sale, 2 = Under Offer, 3 = Sold STC, 4 = Sold, 5 = To Let, 6 = Let Agreed, 7 = Let" | Wrong for this site. The real labels come from the custom theme `09092025154155default` — see the 2026-11-20 bug fix and README §5. `0` shows no ribbon |
| 2026-04-17 and 2026-08-17 | Active listings write `NULL` to `isSold` | The column is `NOT NULL DEFAULT 0`, so MySQL stored `0`. Since 2026-11-20 the importer writes `0` directly |
| 2026-08-17 | web_status `104` = "Lettings Completed", mapped to `isSold = 7` ("Let") | `104` = Let Agreed, and `7` displays "Withdrawn". Fixed 2026-11-20 |
| 2026-04-17 | The "New Instructions" ribbon shows for properties created within the last **3 days** | That is stock OS Property. This site's custom theme uses **7 days** |
| 2026-04-14 (English 404s) | Welsh aliases (`pro_alias_cy`) are stored as plain slugs | The importer does not write any `*_cy` columns. Welsh content and aliases are not imported (README §10) |
| README (17 Nov 2025) | `reset_all_data.php` also clears OS Property categories | It does not. It truncates properties, photos, property-category links and the two importer tables |

**Known issues** recorded in README §10 and not fixed:
- credentials committed to git;
- SSL verification disabled;
- token helper scripts reading the wrong keys;
- removed listings never unpublished;
- count-based image sync;
- `images_imported` column not created automatically;
- Welsh content not imported;
- logs not rotated;
- a `TypeError` that can stop an import run.

#### How to verify
Read README.md from top to bottom alongside the code. Each section points to the file and method it describes.
