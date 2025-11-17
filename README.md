# AltoSync – OS Property Importer (Joomla 5)
This folder contains all scripts used to synchronise property data from the Alto v13 API into the OS Property extension.

There are **three main processes**, each with a dedicated script:

---

## 1. sync.php – Fetch Branches + Property Summaries
This script:
- Connects to Alto API (OAuth v13)
- Fetches all *branches* XML
- Stores full branches XML inside `#__alto_branches`
- Fetches *property summary lists* for every branch
- Stores summary XML into `#__alto_properties`
- Marks entries as `processed = 0` so they are ready for import

### How to run:

   php sync.php


This does **NOT** import anything into OS Property.  
It only prepares the data.

---

## 2. import.php – Import Full XML into OS Property
This script:
- Looks for rows in `#__alto_properties` where `processed = 0`
- For each one:
  - Fetches **full property XML** using the URL in the summary XML
  - Saves the full XML into `/xml/full_properties/<id>.xml`
  - Calls the main mapper:  
    ```
    OsPropertyMapper::mapPropertyDetailsToDatabase()
    ```
  - Writes all details to OS Property tables
  - Awards `processed = 1` in `#__alto_properties`

### How to run:

php import.php


This script performs the heavy lifting.

---

# Mapping Architecture

All mapping happens inside:

Mapper/OsPropertyMapper.php


Which now internally calls:

### ✔ Standard mappers (already existed)
- ImagesMapper  
- CategoryMapper  
- BrochureMapper  
- CompanyMapper  
- Amenities mapper  
- Field/attribute mapping  

### ✔ New mappers (added November 2025)
- `PlansMapper.php`  
  Maps Alto `<file type="2">` URLs → `pro_pdf_file2–4`

- `EnergyRatingMapper.php`  
  Maps Alto `<file type="9">` URLs → `pro_pdf_file5`

- `AutoResetHelper.php`  
  Clears `pro_pdf_file1–9` before importing updated file URLs

These mappers are executed automatically when `import.php` runs.

---

# 3. reset_all_data.php – Danger Zone (Development Only)
This script **truncates** importer and OS Property tables.

It removes:
- All branch data
- All property summary data
- All OS Property property records
- All OS Property photos
- All OS Property categories

### How to run:

php reset_all_data.php


### ⚠️ Warning  
This completely clears the site’s property database.

---

# 🔄 Updating a Single Property (Manual Refresh)

Sometimes you want to refresh **just one property**, without running the full sync/import cycle.

### Follow these steps:

---

## STEP 1 — Run sync.php for just that one property  
If the summary XML already exists in `#__alto_properties`, skip this step.

If not, run:

php sync.php


---

## STEP 2 — Run import.php (only reimports unprocessed entries)
If you manually set a specific property back to `processed = 0`:

UPDATE qrk8g_alto_properties SET processed = 0 WHERE alto_property_id = '123456';


Then run:

php import.php


This will re-import **only that property**, not everything else.

### ✔ This is the safest method  
### ✔ This uses the full real mapping pipeline  
### ✔ Includes PlansMapper, EnergyRatingMapper, AutoResetHelper, etc.

---

## Optional: Dedicated One-Property Script (If Needed)
If desired, we can create:

update_single_property.php


which:
- Reads summary XML
- Fetches full XML
- Calls OsPropertyMapper directly

Ask ChatGPT: *"Generate update_single_property.php"*  
and I will build it.

---

# File Structure (Important)

cli/alto-sync/
│
├── sync.php (Fetch lists)
├── import.php (Map full XML → OS Property)
├── reset_all_data.php (Development wipe script)
│
├── AltoApi.php
├── Logger.php
├── config.php
│
├── Mapper/
│ ├── OsPropertyMapper.php
│ ├── CategoryMapper.php
│ ├── BrochureMapper.php
│ ├── PlansMapper.php ← NEW
│ ├── EnergyRatingMapper.php ← NEW
│ └── (future mappers go here)
│
├── Helpers/
│ └── AutoResetHelper.php ← NEW
│
└── xml/
└── full_properties/ (Full property exports)


---

# Troubleshooting

### ✔ Property not updating?
Set `processed = 0` manually, then re-run `import.php`.

### ✔ Photos not appearing?
Run:

php reset_all_data.php
php sync.php
php import.php


### ✔ File URLs missing?
Check:
- `<files>` exists in full XML  
- file types 2 & 9 appear  
- PlansMapper / EnergyRatingMapper are included in OsPropertyMapper  

---

# Notes for Future Development
- Might add Virtual Tour mapper (file type ??)
- Later: Auto-remove orphan OS Property records no longer in Alto
- Optional: Joomla Admin UI for manual re-sync of individual property

---

# Versioning
This README last updated: **17 November 2025**