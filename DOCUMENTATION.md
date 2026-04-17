# AltoSync – OS Property Importer: Technical Documentation

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
> 5. Do not delete or edit previous entries — this is a historical log.
> 6. Keep language clear and non-technical where possible, as this document may be read by the client.

---

## Bug Fixes

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

*No feature additions documented yet.*

---

## Changes

*No general changes documented yet.*
