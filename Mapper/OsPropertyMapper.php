<?php
// /public_html/cli/alto-sync/Mapper/OsPropertyMapper.php
// Maps Alto XML data into OS Property tables (properties, branches, categories, PDFs, etc.)

namespace AltoSync\Mapper;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Logger.php';
require_once __DIR__ . '/../Helpers/AutoResetHelper.php';

require_once __DIR__ . '/AddressMapper.php';
require_once __DIR__ . '/CategoryMapper.php';
require_once __DIR__ . '/BrochureMapper.php';
require_once __DIR__ . '/PlansMapper.php';
require_once __DIR__ . '/EnergyRatingMapper.php';
require_once __DIR__ . '/StatusMapper.php';
require_once __DIR__ . '/ImagesMapper.php';

use AltoSync\Logger;
use AltoSync\Helpers\AutoResetHelper;
use AltoSync\Mapper\AddressMapper;
use AltoSync\Mapper\CategoryMapper;
use AltoSync\Mapper\BrochureMapper;
use AltoSync\Mapper\PlansMapper;
use AltoSync\Mapper\EnergyRatingMapper;
use AltoSync\Mapper\StatusMapper;
use AltoSync\Mapper\ImagesMapper;

/**
 * Class OsPropertyMapper
 * Main mapper between Alto XML and OS Property DB.
 */
class OsPropertyMapper
{
    /** @var \PDO|null */
    private static $db = null;

    /**
     * Init the DB connection (shared).
     */
    private static function initDb(): void
    {
        if (self::$db !== null) {
            return;
        }

        try {
            self::$db = new \PDO(
                'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
                \DB_USER,
                \DB_PASS,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            self::$db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            self::$db->setAttribute(\PDO::ATTR_AUTOCOMMIT, true);
        } catch (\PDOException $e) {
            Logger::log("Database connection failed in OsPropertyMapper: " . $e->getMessage(), 'CRITICAL');
            throw new \RuntimeException("Database connection failed in mapper.");
        }
    }

    /**
     * Simple slug generator to replace JFilterOutput::stringURLSafe in CLI.
     */
    private static function createSlug(string $string): string
    {
        $string = strtolower(trim($string));
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        $string = preg_replace('/-+/', '-', $string);
        return trim($string, '-');
    }

    /**
     * Generic lookup helper (cities, states, countries, etc.).
     */
    public static function getOrCreateLookupId(\PDO $db, string $tableName, string $nameColumn, string $nameValue)
    {
        $nameValue = trim($nameValue);
        if ($nameValue === '') {
            Logger::log("Attempted to lookup/create {$tableName} with empty {$nameColumn}", 'WARNING');
            return 0;
        }

        $prefixedTableName = \DB_PREFIX . $tableName;
        $nameColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $nameColumn);

        try {
            $stmt = $db->prepare("SELECT id FROM `{$prefixedTableName}` WHERE `{$nameColumn}` = ?");
            $stmt->execute([$nameValue]);
            $existingId = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($existingId) {
                return (int) $existingId;
            }

            $stmt = $db->prepare("INSERT INTO `{$prefixedTableName}` (`{$nameColumn}`, published) VALUES (?, 1)");
            $stmt->execute([$nameValue]);
            $newId = $db->lastInsertId();
            return (int) $newId;
        } catch (\PDOException $e) {
            Logger::log("DB error in getOrCreateLookupId for {$prefixedTableName} ({$nameValue}): " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Get/create Joomla user record for Alto negotiator.
     */
    public static function getOrCreateAgentId(
        \PDO $db,
        string $negotiatorId,
        string $negotiatorName,
        string $negotiatorEmail,
        string $negotiatorPhone
    ) {
        $negotiatorEmail = trim($negotiatorEmail);

        if ($negotiatorEmail === '' || !filter_var($negotiatorEmail, FILTER_VALIDATE_EMAIL)) {
            Logger::log("Cannot create agent: invalid/empty email for negotiator {$negotiatorId}", 'WARNING');
            return false;
        }

        // Lookup by custom alto_negotiator_id
        $stmt = $db->prepare("SELECT id FROM `" . \DB_PREFIX . "users` WHERE `alto_negotiator_id` = ?");
        $stmt->execute([$negotiatorId]);
        $existingUserId = $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($existingUserId) {
            $stmt = $db->prepare("
                UPDATE `" . \DB_PREFIX . "users`
                SET `name` = ?, `email` = ?, `modified` = NOW()
                WHERE `id` = ?
            ");
            $stmt->execute([$negotiatorName, $negotiatorEmail, $existingUserId]);
            Logger::log("Updated existing agent (Joomla ID {$existingUserId}) for Alto Negotiator {$negotiatorId}", 'INFO');
            return (int) $existingUserId;
        }

        // Create new user
        $username = strtolower(str_replace(' ', '', $negotiatorName)) . '_' . $negotiatorId;
        $username = substr(preg_replace('/[^a-z0-9]/', '', $username), 0, 150);
        $username = $username . '_' . uniqid();

        $password = password_hash(substr(md5(rand()), 0, 8), PASSWORD_DEFAULT);

        try {
            $stmt = $db->prepare("
                INSERT INTO `" . \DB_PREFIX . "users` (
                    `name`, `username`, `email`, `password`, `registerDate`,
                    `lastvisitDate`, `activation`, `sendEmail`, `block`,
                    `requireReset`, `alto_negotiator_id`
                ) VALUES (
                    ?, ?, ?, ?, NOW(),
                    '0000-00-00 00:00:00', '',
                    0, 0,
                    0, ?
                )
            ");
            $stmt->execute([$negotiatorName, $username, $negotiatorEmail, $password, $negotiatorId]);
            $newId = $db->lastInsertId();

            if ($newId) {
                Logger::log("Created new Joomla user (ID {$newId}) for Alto Negotiator {$negotiatorId}", 'INFO');

                $groupStmt = $db->prepare("
                    INSERT IGNORE INTO `" . \DB_PREFIX . "user_usergroup_map` (user_id, group_id)
                    VALUES (?, ?)
                ");
                $groupStmt->execute([$newId, 2]);
                return (int) $newId;
            }
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                Logger::log("Duplicate user creation attempt for negotiator {$negotiatorId} ({$negotiatorEmail}): " . $e->getMessage(), 'WARNING');
                $stmt = $db->prepare("SELECT id FROM `" . \DB_PREFIX . "users` WHERE `username` = ? OR `email` = ?");
                $stmt->execute([$username, $negotiatorEmail]);
                $existingId = $stmt->fetchColumn();
                $stmt->closeCursor();
                if ($existingId) {
                    Logger::log("Found existing Joomla user ID {$existingId} for negotiator {$negotiatorId}", 'INFO');
                    return (int) $existingId;
                }
            }
            Logger::log("DB error creating agent for Alto Negotiator {$negotiatorId}: " . $e->getMessage(), 'CRITICAL');
            return false;
        }

        return false;
    }

    /**
     * Map Alto branch XML <branch> into #__osrs_companies.
     */
    public static function mapBranchDetailsToDatabase(\SimpleXMLElement $branchXmlObject): bool
    {
        self::initDb();

        try {
            $branchid = (string) $branchXmlObject->branchid;
            $branchName = (string) $branchXmlObject->name ?: 'Branch ' . $branchid;
            $branchUrl  = (string) $branchXmlObject->url;

            $addressLine1 = (string) $branchXmlObject->address->line1;
            $addressLine2 = (string) $branchXmlObject->address->line2;
            $addressLine3 = (string) $branchXmlObject->address->line3;
            $town         = (string) $branchXmlObject->address->town;
            $postcode     = (string) $branchXmlObject->address->postcode;
            $country      = (string) $branchXmlObject->address->country ?: 'United Kingdom';

            $email     = (string) $branchXmlObject->email;
            $telephone = (string) $branchXmlObject->telephone;
            $fax       = (string) $branchXmlObject->fax;
            $website   = (string) $branchXmlObject->website;

            $cityId    = self::getOrCreateLookupId(self::$db, 'osrs_cities', 'city', $town);
            $countryId = self::getOrCreateLookupId(self::$db, 'osrs_countries', 'country_name', $country);

            $fullAddress = trim(implode(', ', array_filter([$addressLine1, $addressLine2, $addressLine3])));

            // Existing?
            $stmt = self::$db->prepare("
                SELECT id
                FROM `" . \DB_PREFIX . "osrs_companies`
                WHERE alto_branch_id = ?
            ");
            $stmt->execute([$branchid]);
            $existingId = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($existingId) {
                Logger::log(
                    "Existing Branch (Company) ID {$existingId} for Alto Branch {$branchid} – skipping update to preserve manual data.",
                    'INFO'
                );
                return true;
            }

            // Insert new
            Logger::log("Inserting new Branch (Company) for Alto Branch ID {$branchid}.", 'INFO');
            $stmt = self::$db->prepare("
                INSERT INTO `" . \DB_PREFIX . "osrs_companies` (
                    alto_branch_id, company_name, company_alias, email, phone, fax,
                    address, city, country, website, postcode, published, user_id
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, 1, 0
                )
            ");
            $ok = $stmt->execute([
                $branchid,
                $branchName,
                self::createSlug($branchName),
                $email,
                $telephone,
                $fax,
                $fullAddress,
                $cityId,
                $countryId,
                $website,
                $postcode
            ]);
            $newId = self::$db->lastInsertId();
            if ($ok) {
                Logger::log("New Branch inserted for Alto {$branchid} (Company ID {$newId}).", 'INFO');
                return true;
            }

            Logger::log("Failed to insert branch {$branchid}: " . json_encode($stmt->errorInfo()), 'ERROR');
            return false;
        } catch (\PDOException $e) {
            Logger::log("DB error mapping branch to osrs_companies: " . $e->getMessage(), 'ERROR');
            return false;
        } catch (\Exception $e) {
            Logger::log("General error mapping branch to osrs_companies: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Map Alto <type> to OS Property type (osrs_types).
     */
    public static function getOrCreatePropertyTypeId(\PDO $db, string $altoPropertyType): int
    {
        $altoPropertyType = trim($altoPropertyType);
        Logger::log("  getOrCreatePropertyTypeId - Alto type: '{$altoPropertyType}'", 'DEBUG');

        if ($altoPropertyType === '') {
            Logger::log("  WARNING: Empty <type> in Alto – defaulting to 'Unknown' (ID 7).", 'WARNING');
            return 7;
        }

        $standardizedType = 'Other';
        $lower = strtolower($altoPropertyType);

        if (str_contains($lower, 'house')) {
            $standardizedType = 'House';
        } elseif (str_contains($lower, 'bungalow')) {
            $standardizedType = 'Bungalow';
        } elseif (str_contains($lower, 'flat') || str_contains($lower, 'apartment')) {
            $standardizedType = 'Flat';
        } elseif (str_contains($lower, 'maisonette')) {
            $standardizedType = 'Maisonette';
        } elseif (str_contains($lower, 'land')) {
            $standardizedType = 'Land';
        } elseif (str_contains($lower, 'farm')) {
            $standardizedType = 'Farm';
        } elseif (str_contains($lower, 'commercial')) {
            $standardizedType = 'Commercial';
        } elseif (str_contains($lower, 'garage')) {
            $standardizedType = 'Garage';
        } elseif (str_contains($lower, 'parking')) {
            $standardizedType = 'Parking';
        }

        Logger::log("  getOrCreatePropertyTypeId - Standardized '{$altoPropertyType}' → '{$standardizedType}'", 'DEBUG');

        $table = \DB_PREFIX . 'osrs_types';

        try {
            $stmt = $db->prepare("SELECT id FROM `{$table}` WHERE `type_name` = ?");
            $stmt->execute([$standardizedType]);
            $id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($id) {
                Logger::log("  Found OS Property Type '{$standardizedType}' (ID {$id})", 'DEBUG');
                return (int) $id;
            }

            $stmt = $db->prepare("INSERT INTO `{$table}` (`type_name`, `published`) VALUES (?, 1)");
            $stmt->execute([$standardizedType]);
            $newId = $db->lastInsertId();
            Logger::log("  Created new OS Property Type '{$standardizedType}' (ID {$newId})", 'INFO');
            return (int) $newId;
        } catch (\PDOException $e) {
            Logger::log("  ERROR in getOrCreatePropertyTypeId for '{$standardizedType}': " . $e->getMessage(), 'ERROR');
            return 7;
        }
    }

    /**
     * Fallback category resolver from Alto web_status.
     */
    public static function getOrCreateCategoryId(\PDO $db, string $altoWebStatus): int
    {
        $altoWebStatus = trim($altoWebStatus);
        Logger::log("  getOrCreateCategoryId - Alto web_status='{$altoWebStatus}'", 'DEBUG');

        if ($altoWebStatus === '') {
            Logger::log("  WARNING: Empty web_status – defaulting Category ID 7 (Unknown).", 'WARNING');
            return 7;
        }

        $categoryName  = 'Unknown Status';
        $numericStatus = is_numeric($altoWebStatus) ? (int) $altoWebStatus : null;

        if ($numericStatus !== null) {
            switch ($numericStatus) {
                case 0:  $categoryName = 'For Sale / To Let'; break;
                case 1:  $categoryName = 'Let Agreed / Under Offer'; break;
                case 2:  $categoryName = 'Let'; break;
                case 3:  $categoryName = 'Withdrawn'; break;
                case 4:  $categoryName = 'Completed'; break;
                case 100: $categoryName = 'To Let (New Lettings)'; break;
                case 101: $categoryName = 'Let Agreed (New Lettings)'; break;
                case 102: $categoryName = 'Let (New Lettings)'; break;
                case 103: $categoryName = 'Withdrawn (New Lettings)'; break;
                case 104: $categoryName = 'Completed (New Lettings)'; break;
                default:
                    $categoryName = 'Unknown Status ' . $numericStatus;
                    Logger::log("  WARNING: Unknown numerical web_status '{$numericStatus}'", 'WARNING');
                    break;
            }
        } else {
            $lower = strtolower($altoWebStatus);
            if (str_contains($lower, 'sold')) {
                $categoryName = 'Sold';
            } elseif (str_contains($lower, 'let')) {
                $categoryName = 'To Let';
            } elseif (str_contains($lower, 'for sale')) {
                $categoryName = 'For Sale';
            } elseif (str_contains($lower, 'under offer')) {
                $categoryName = 'Under Offer';
            } elseif (str_contains($lower, 'pending') || str_contains($lower, 'stc')) {
                $categoryName = 'Pending';
            } elseif (str_contains($lower, 'available')) {
                $categoryName = 'Available';
            } else {
                $categoryName = $altoWebStatus;
                Logger::log("  WARNING: Unknown string web_status '{$altoWebStatus}'", 'WARNING');
            }
        }

        $table = \DB_PREFIX . 'osrs_categories';

        try {
            $stmt = $db->prepare("SELECT id FROM `{$table}` WHERE `category_name` = ?");
            $stmt->execute([$categoryName]);
            $id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($id) {
                Logger::log("  Found OS Property Category '{$categoryName}' (ID {$id})", 'DEBUG');
                return (int) $id;
            }

            $stmt = $db->prepare("
                INSERT INTO `{$table}` (`category_name`, `category_alias`, `published`)
                VALUES (?, ?, 1)
            ");
            $stmt->execute([$categoryName, self::createSlug($categoryName)]);
            $newId = $db->lastInsertId();
            Logger::log("  Created OS Property Category '{$categoryName}' (ID {$newId})", 'INFO');
            return (int) $newId;
        } catch (\PDOException $e) {
            Logger::log("  ERROR in getOrCreateCategoryId for '{$categoryName}': " . $e->getMessage(), 'ERROR');
            return 7;
        }
    }

    /**
     * Older fallback for category_id (used when CategoryMapper returns null).
     * 5 = Sales, 6 = Lettings, 7 = Commercial.
     */
    private static function determineCategoryId(\SimpleXMLElement $p): int
    {
        $dept = strtolower(trim((string) ($p->department ?? $p->web_department ?? '')));
        $type = strtolower(trim((string) ($p->type ?? '')));

        $wsAttr = (string) ($p->web_status['id'] ?? '');
        $wsText = strtolower(trim((string) ($p->web_status ?? '')));

        $qualifier = strtolower(trim((string) ($p->price->qualifier ?? '')));
        $display   = strtolower(trim((string) ($p->price->display_text ?? '')));

        // 1) Commercial
        if (
            $dept === 'commercial' ||
            str_contains($type, 'commercial') ||
            str_contains($type, 'office') ||
            str_contains($type, 'retail') ||
            str_contains($type, 'industrial') ||
            str_contains($type, 'warehouse') ||
            str_contains($type, 'shop') ||
            str_contains($type, 'restaurant') ||
            str_contains($type, 'bar')
        ) {
            return 7;
        }

        // 2) Dept hints
        if ($dept === 'lettings' || $dept === 'rental' || $dept === 'to let') {
            return 6;
        }
        if ($dept === 'sales' || $dept === 'for sale') {
            return 5;
        }

        // 3) web_status attribute
        if ($wsAttr !== '' && ctype_digit($wsAttr)) {
            $n = (int) $wsAttr;
            if ($n >= 100 && $n <= 104) return 6;
            if ($n >= 0 && $n <= 4)    return 5;
        }

        // 4) text
        if (str_contains($wsText, 'let'))  return 6;
        if (str_contains($wsText, 'sale')) return 5;

        // 5) Price text
        if (
            str_contains($qualifier, 'pcm') || str_contains($qualifier, 'pw') ||
            str_contains($display, 'pcm')   || str_contains($display, 'per week') ||
            str_contains($display, 'per calendar month')
        ) {
            return 6;
        }

        // 6) default Sales
        return 5;
    }

    /**
     * Keep #__osrs_property_categories in sync with a single category_id.
     */
    private static function upsertPropertyCategoryLink(int $propertyOsId, int $categoryId): void
    {
        self::initDb();

        try {
            $stmtDel = self::$db->prepare("
                DELETE FROM `" . \DB_PREFIX . "osrs_property_categories`
                WHERE pid = ?
            ");
            $stmtDel->execute([$propertyOsId]);
            $stmtDel->closeCursor();

            $stmtIns = self::$db->prepare("
                INSERT INTO `" . \DB_PREFIX . "osrs_property_categories` (pid, category_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)
            ");
            $stmtIns->execute([$propertyOsId, $categoryId]);
            $stmtIns->closeCursor();

            Logger::log("    Category link synced for PID {$propertyOsId} → category {$categoryId}", 'INFO');
        } catch (\PDOException $e) {
            Logger::log("    ERROR syncing category link for PID {$propertyOsId}: " . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * Fetch OS Property currency id by ISO code (e.g. GBP). Create if missing.
     */
    private static function getCurrencyIdByIso(string $isoOrEmpty): int
    {
        self::initDb();

        $code = strtoupper(trim($isoOrEmpty ?: 'GBP'));

        try {
            $stmt = self::$db->prepare("
                SELECT id
                FROM `" . \DB_PREFIX . "osrs_currencies`
                WHERE UPPER(currency_code) = ?
            ");
            $stmt->execute([$code]);
            $id = (int) $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($id > 0) {
                return $id;
            }

            if ($code === 'GBP') {
                $stmt = self::$db->query("
                    SELECT id
                    FROM `" . \DB_PREFIX . "osrs_currencies`
                    WHERE UPPER(currency_code) IN ('GBP','UKP')
                ");
                $id = (int) $stmt->fetchColumn();
                if ($id > 0) {
                    return $id;
                }
            }

            $ins = self::$db->prepare("
                INSERT INTO `" . \DB_PREFIX . "osrs_currencies`
                    (currency_name, currency_code, currency_symbol, published)
                VALUES (?, ?, ?, 1)
            ");
            $name   = ($code === 'GBP') ? 'Pound Sterling' : $code;
            $symbol = ($code === 'GBP') ? '£' : $code;
            $ins->execute([$name, $code, $symbol]);

            return (int) self::$db->lastInsertId();
        } catch (\PDOException $e) {
            Logger::log("Currency lookup/insert failed for '{$code}': " . $e->getMessage(), 'ERROR');
            return 1;
        }
    }

    /**
     * Fetch OS Property ID by Alto ID (returns 0 if not found).
     */
    private static function getOsPropertyIdByAltoId(string $altoId): int
    {
        self::initDb();
        try {
            $stmt = self::$db->prepare("
                SELECT id
                FROM `" . \DB_PREFIX . "osrs_properties`
                WHERE alto_id = ?
            ");
            $stmt->execute([$altoId]);
            $pid = (int) ($stmt->fetchColumn() ?: 0);
            $stmt->closeCursor();
            return $pid;
        } catch (\PDOException $e) {
            Logger::log("getOsPropertyIdByAltoId failed for alto_id={$altoId}: " . $e->getMessage(), 'ERROR');
            return 0;
        }
    }

    /**
     * Helper used by other scripts to decide if a property has any images yet.
     */
    public static function propertyNeedsImages(string $altoId): bool
    {
        self::initDb();

        $pid = self::getOsPropertyIdByAltoId($altoId);
        if ($pid <= 0) {
            return false;
        }

        // DB check
        try {
            $stmt = self::$db->prepare("
                SELECT COUNT(*)
                FROM `" . \DB_PREFIX . "osrs_photos`
                WHERE pro_id = ?
            ");
            $stmt->execute([$pid]);
            $count = (int) $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($count > 0) {
                return false;
            }
        } catch (\PDOException $e) {
            Logger::log("propertyNeedsImages DB check failed for alto_id={$altoId}: " . $e->getMessage(), 'ERROR');
            return false;
        }

        // Filesystem check
        $base     = rtrim(\PROPERTY_IMAGE_UPLOAD_BASE_PATH, '/');
        $propDir  = $base . '/' . $pid . '/';
        $thumbDir = $propDir . 'thumb/';
        $medDir   = $propDir . 'medium/';

        $hasAny = function (string $dir): bool {
            if (!is_dir($dir)) return false;
            $list = glob($dir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
            return is_array($list) && count($list) > 0;
        };

        if ($hasAny($propDir) || $hasAny($thumbDir) || $hasAny($medDir)) {
            return false;
        }

        return true;
    }

    /**
     * Main property mapper: maps full Alto <property> XML into #__osrs_properties and related tables.
     */
    public static function mapPropertyDetailsToDatabase(\SimpleXMLElement $propertyXmlObject, $altoBranchId): bool
    {
        self::initDb();
        Logger::log("  Starting property mapping.", 'INFO');

        self::$db->beginTransaction();
        $success      = false;
        $propertyOsId = null;
        $altoId       = null;

        try {
            // Alto ID
            $altoId = (string) $propertyXmlObject->attributes()->id;
            if (trim($altoId) === '' && isset($propertyXmlObject->prop_id)) {
                $altoId = (string) $propertyXmlObject->prop_id;
            }
            if (trim($altoId) === '') {
                Logger::log("  ERROR: Missing Alto Property ID in full XML.", 'ERROR');
                self::$db->rollBack();
                return false;
            }

            Logger::log("  Processing Alto Property ID: {$altoId}", 'INFO');

            // Use <lastchanged> from alto_properties summary XML for created/modified
            $lastChanged = null;
            try {
                $stmtLC = self::$db->prepare("
                    SELECT xml_data
                    FROM `" . \DB_PREFIX . "alto_properties`
                    WHERE alto_property_id = ?
                    LIMIT 1
                ");
                $stmtLC->execute([$altoId]);
                $xmlRaw = $stmtLC->fetchColumn();
                $stmtLC->closeCursor();

                if ($xmlRaw) {
                    $xmlObj = simplexml_load_string($xmlRaw);
                    if ($xmlObj && isset($xmlObj->lastchanged)) {
                        $raw = (string) $xmlObj->lastchanged;
                        $clean = preg_replace('/\.\d+$/', '', $raw);
                        $clean = str_replace('T', ' ', $clean);
                        $lastChanged = $clean;
                    }
                }
            } catch (\Exception $e) {
                Logger::log("  ERROR getting <lastchanged> for Alto ID {$altoId}: " . $e->getMessage(), 'ERROR');
            }

            if (!$lastChanged) {
                $lastChanged = date('Y-m-d H:i:s');
            }
            Logger::log("  Using lastchanged timestamp: {$lastChanged}", 'INFO');

            $createdDate  = substr($lastChanged, 0, 10);
            $modifiedDate = substr($lastChanged, 0, 10);

            // Web status and STATUSMAPPER
            $webStatus = (string) (
                $propertyXmlObject->web_status['id']
                ?? $propertyXmlObject->web_status
                ?? 'available'
            );

            $status = StatusMapper::map($webStatus);
            $propertyIsSold   = null;
            $extraFieldStatus = null;

            if ($status !== null) {
                $propertyIsSold   = $status['isSold'];
                $extraFieldStatus = $status['label'];
                Logger::log(
                    "  STATUSMAPPER: web_status={$webStatus} → isSold={$propertyIsSold} ({$extraFieldStatus})",
                    'INFO'
                );
            }

            // Core fields
            $propertyTypeAlto = (string) $propertyXmlObject->type ?: 'Unknown';
            $bedrooms         = (int) $propertyXmlObject->bedrooms;
            $bathrooms        = (float) $propertyXmlObject->bathrooms;
            $receptions       = (int) $propertyXmlObject->receptions;

            // Address via AddressMapper
            $addressMapper = new AddressMapper(self::$db);
            $addressData   = $addressMapper->mapAddress($propertyXmlObject->address, 'United Kingdom');

            $propertyTitle = (string) $propertyXmlObject->address->display
                ?: trim(implode(', ', array_filter([
                    (string) $propertyXmlObject->address->name,
                    (string) $propertyXmlObject->address->street,
                    (string) $propertyXmlObject->address->locality,
                ])))
                ?: "Property ID: {$altoId}";

            $concatAddress = $addressData['address'];
            $cityId        = $addressData['city_id'];
            $stateId       = $addressData['state_id'];
            $countryId     = $addressData['country_id'];
            $postcode      = $addressData['postcode'];

            // Price
            $priceRaw       = (string) ($propertyXmlObject->price->value ?? $propertyXmlObject->price ?? '');
            $priceValue     = (int) preg_replace('/[^\d]/', '', $priceRaw);
            $priceQualifier = (string) ($propertyXmlObject->price->qualifier ?? '');
            $displayText    = (string) ($propertyXmlObject->price->display_text ?? '');

            $summary     = (string) ($propertyXmlObject->summary ?? '');
            $description = (string) ($propertyXmlObject->description ?? '');

            if ($summary === '' && $description !== '') {
                $summary = mb_substr(trim(strip_tags($description)), 0, 300);
            }

            $dateAdded        = (string) $propertyXmlObject->date_added;
            $dateLastModified = (string) $propertyXmlObject->date_last_modified;

            $tenure    = (string) $propertyXmlObject->tenure;
            $yearBuilt = (int) $propertyXmlObject->year_built;

            $epcCurrentEnergyEfficiency  = (string) $propertyXmlObject->epc->current_energy_efficiency;
            $epcCurrentEnvironmentalImpact = (string) $propertyXmlObject->epc->current_environmental_impact;

            $totalFloorAreaSqft = (float) $propertyXmlObject->floor_area->total_floor_area_sqft;
            $totalLandAreaSqft  = (float) $propertyXmlObject->land_area->total_land_area_sqft;

            $agentUserId      = 1; // fixed as agreed
            $companyJoomlaId  = 0; // fixed as agreed
            $latitude         = (string) $propertyXmlObject->latitude;
            $longitude        = (string) $propertyXmlObject->longitude;

            // Property type + category mapping
            $propertyTypeId = self::getOrCreatePropertyTypeId(self::$db, $propertyTypeAlto);

            // Determine Market + Category and map to category_id
            $webStatusAttr = (string) ($propertyXmlObject->web_status['id'] ?? '');
            $webStatusNum  = is_numeric($webStatusAttr)
                ? (int) $webStatusAttr
                : (int) ($propertyXmlObject->web_status ?? 0);

            $isCommercial = isset($propertyXmlObject->commercial);
            $transaction  = strtolower((string) ($propertyXmlObject->commercial->transaction ?? ''));

            // Market
            if ($webStatusNum >= 100 && $webStatusNum <= 104) {
                $market = 'To Let';
            } elseif ($webStatusNum >= 0 && $webStatusNum <= 4) {
                $market = 'For Sale';
            } elseif (in_array($transaction, ['rental', 'let', 'lease'], true)) {
                $market = 'To Let';
            } elseif (in_array($transaction, ['sale', 'sales'], true)) {
                $market = 'For Sale';
            } else {
                $market = (string) ($propertyXmlObject->department
                    ?? $propertyXmlObject->marketing->market
                    ?? '');
            }

            // Category
            if ($isCommercial || str_contains(strtolower((string) $propertyXmlObject->type), 'commercial')) {
                $category = 'Commercial';
            } else {
                $category = 'Residential';
            }

            Logger::log("  Inferred Market='{$market}', Category='{$category}' (web_status={$webStatusNum}, transaction='{$transaction}')", 'DEBUG');

            $databaseAttr   = (string) ($propertyXmlObject['database'] ?? '');
            $mappedCategory = CategoryMapper::toOsCategoryId($market, $category, $databaseAttr);

            if ($mappedCategory !== null) {
                $categoryId = $mappedCategory;
                Logger::log("  CategoryMapper: '{$market}' + '{$category}' → Category ID {$categoryId}", 'INFO');
            } else {
                $categoryId = self::determineCategoryId($propertyXmlObject);
                Logger::log("  CategoryMapper null; using determineCategoryId() → Category ID {$categoryId}", 'INFO');
            }

            Logger::log("  Final: Alto property {$altoId} → type_id={$propertyTypeId}, category_id={$categoryId}", 'INFO');

            // Published status (kept simple – always published here)
            $osPropertyStatus = 1;

            // Currency
            $currencyFromAttr = (string) ($propertyXmlObject->price['currency_code'] ?? '');
            $currencyFromNode = (string) ($propertyXmlObject->price->currency ?? '');
            $currencyIso      = $currencyFromAttr ?: $currencyFromNode ?: 'GBP';
            $currencyId       = self::getCurrencyIdByIso($currencyIso);

            // Reference
            $propertyRef = (string) $altoId;

            // Brochures / PDFs
            $pdfData = BrochureMapper::map($propertyXmlObject);

            $proPdfFile  = $pdfData['pro_pdf_file']  ?? '';
            $proPdfFile1 = $pdfData['pro_pdf_file1'] ?? '';
            $proPdfFile2 = $pdfData['pro_pdf_file2'] ?? '';
            $proPdfFile3 = $pdfData['pro_pdf_file3'] ?? '';
            $proPdfFile4 = $pdfData['pro_pdf_file4'] ?? '';
            $proPdfFile5 = $pdfData['pro_pdf_file5'] ?? '';
            $proPdfFile6 = $pdfData['pro_pdf_file6'] ?? '';
            $proPdfFile7 = $pdfData['pro_pdf_file7'] ?? '';
            $proPdfFile8 = $pdfData['pro_pdf_file8'] ?? '';
            $proPdfFile9 = $pdfData['pro_pdf_file9'] ?? '';

            Logger::log("  BrochureMapper done – primary: {$proPdfFile}", 'DEBUG');

            // INSERT/UPDATE for #__osrs_properties
            $params = [
                $altoId,
                $propertyTitle,
                $propertyTypeId,
                self::createSlug($propertyTitle),
                $concatAddress,
                $countryId,
                $stateId,
                $cityId,
                $postcode,
                $summary,
                $description,
                $priceValue,
                $displayText,
                $currencyId,
                $bedrooms,
                $bathrooms,
                $receptions,
                $latitude,
                $longitude,
                $propertyRef,
                $agentUserId,
                $osPropertyStatus,
                0,     // hits (insert)
                1,     // approved
                1,     // access
                $createdDate,
                $modifiedDate,
                $companyJoomlaId,
                $categoryId,
                $totalFloorAreaSqft,
                $totalLandAreaSqft,
                $yearBuilt,
                $proPdfFile,
                $proPdfFile1,
                $proPdfFile2,
                $proPdfFile3,
                $proPdfFile4,
                $proPdfFile5,
                $proPdfFile6,
                $proPdfFile7,
                $proPdfFile8,
                $proPdfFile9,
            ];

            $sql = "
                INSERT INTO `" . \DB_PREFIX . "osrs_properties` (
                    alto_id, pro_name, pro_type, pro_alias, address,
                    country, state, city, postcode, pro_small_desc, pro_full_desc,
                    price, price_text, curr, bed_room, bath_room, rooms,
                    lat_add, long_add, ref, agent_id, published, hits, approved, access,
                    created, modified, company_id, category_id,
                    square_feet, lot_size, built_on,
                    pro_pdf_file, pro_pdf_file1, pro_pdf_file2, pro_pdf_file3, pro_pdf_file4,
                    pro_pdf_file5, pro_pdf_file6, pro_pdf_file7, pro_pdf_file8, pro_pdf_file9
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?
                ) ON DUPLICATE KEY UPDATE
                    pro_name       = VALUES(pro_name),
                    pro_type       = VALUES(pro_type),
                    pro_alias      = VALUES(pro_alias),
                    address        = VALUES(address),
                    country        = VALUES(country),
                    state          = VALUES(state),
                    city           = VALUES(city),
                    postcode       = VALUES(postcode),
                    pro_small_desc = VALUES(pro_small_desc),
                    pro_full_desc  = VALUES(pro_full_desc),
                    price          = VALUES(price),
                    price_text     = VALUES(price_text),
                    curr           = VALUES(curr),
                    bed_room       = VALUES(bed_room),
                    bath_room      = VALUES(bath_room),
                    rooms          = VALUES(rooms),
                    lat_add        = VALUES(lat_add),
                    long_add       = VALUES(long_add),
                    ref            = VALUES(ref),
                    agent_id       = VALUES(agent_id),
                    published      = VALUES(published),
                    modified       = VALUES(modified),
                    company_id     = VALUES(company_id),
                    category_id    = VALUES(category_id),
                    square_feet    = VALUES(square_feet),
                    lot_size       = VALUES(lot_size),
                    built_on       = VALUES(built_on),
                    pro_pdf_file   = VALUES(pro_pdf_file),
                    pro_pdf_file1  = VALUES(pro_pdf_file1),
                    pro_pdf_file2  = VALUES(pro_pdf_file2),
                    pro_pdf_file3  = VALUES(pro_pdf_file3),
                    pro_pdf_file4  = VALUES(pro_pdf_file4),
                    pro_pdf_file5  = VALUES(pro_pdf_file5),
                    pro_pdf_file6  = VALUES(pro_pdf_file6),
                    pro_pdf_file7  = VALUES(pro_pdf_file7),
                    pro_pdf_file8  = VALUES(pro_pdf_file8),
                    pro_pdf_file9  = VALUES(pro_pdf_file9),
                    hits           = hits
            ";

            Logger::log("  DEBUG: Property SQL Params: " . json_encode($params), 'DEBUG');

            $stmt = self::$db->prepare($sql);
            $execSuccess = $stmt->execute($params);

            if ($execSuccess) {
                $rowCount = $stmt->rowCount();
                Logger::log("  DEBUG: rowCount for Alto {$altoId}: {$rowCount}", 'DEBUG');

                if ($rowCount === 1) {
                    $propertyOsId = (int) self::$db->lastInsertId();
                    Logger::log("  New OS Property ID {$propertyOsId} created for Alto {$altoId}", 'INFO');
                } else {
                    $stmtGetId = self::$db->prepare("
                        SELECT id
                        FROM `" . \DB_PREFIX . "osrs_properties`
                        WHERE alto_id = ?
                    ");
                    $stmtGetId->execute([$altoId]);
                    $propertyOsId = (int) $stmtGetId->fetchColumn();
                    $stmtGetId->closeCursor();

                    Logger::log("  OS Property updated/fetched for Alto {$altoId} – PID {$propertyOsId}", 'INFO');
                }

                $success = true;

                if ($propertyOsId) {
                    self::upsertPropertyCategoryLink($propertyOsId, (int) $categoryId);
                }

                // StatusMapper: apply isSold if available
                if (isset($propertyIsSold) && $propertyOsId) {
                    $stmtIsSold = self::$db->prepare("
                        UPDATE `" . \DB_PREFIX . "osrs_properties`
                        SET isSold = ?
                        WHERE id = ?
                    ");
                    $stmtIsSold->execute([$propertyIsSold, $propertyOsId]);
                    Logger::log("  STATUSMAPPER: Updated isSold={$propertyIsSold} for PID {$propertyOsId}", 'INFO');
                }
            } else {
                Logger::log("  ERROR: Insert/Update failed for Alto {$altoId}: " . json_encode($stmt->errorInfo()), 'ERROR');
                $success = false;
            }
            unset($stmt);

            // Reset extra PDF slots before floorplans/EPC
            AutoResetHelper::resetExtraFilesObject($propertyXmlObject, $propertyOsId, self::$db);

            // Floorplans
            $plansMapper = new PlansMapper();
            $plansMapper->map($propertyOsId, $propertyXmlObject, self::$db);

            // EPC / Energy Rating
            $epcMapper = new EnergyRatingMapper();
            $epcMapper->map($propertyOsId, $propertyXmlObject, self::$db);

            // IMAGES – via ImagesMapper (Smart Skip)
            if ($success && $propertyOsId) {
                $imagesImportedFlag = isset($GLOBALS['altoImagesImportedFlag'])
                    ? (int) $GLOBALS['altoImagesImportedFlag']
                    : 0;

                ImagesMapper::importImages(
                    (int) $propertyOsId,
                    $propertyXmlObject,
                    (string) $altoId,
                    $imagesImportedFlag
                );
            }

            // Features (placeholder)
            if (isset($propertyXmlObject->features) && $propertyXmlObject->features->feature) {
                foreach ($propertyXmlObject->features->feature as $feature) {
                    $featureText = (string) $feature;
                    Logger::log("    Feature for Alto {$altoId}: {$featureText}", 'INFO');
                }
            }

            if (self::$db->inTransaction()) {
                if ($success) {
                    self::$db->commit();
                    Logger::log("  Transaction committed for Alto {$altoId}", 'DEBUG');
                } else {
                    self::$db->rollBack();
                    Logger::log("  Transaction rolled back for Alto {$altoId} (failure).", 'DEBUG');
                }
            }

            return $success;
        } catch (\PDOException $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
                Logger::log("  Transaction rolled back for Alto " . ($altoId ?? 'N/A') . " due to PDOException.", 'DEBUG');
            }
            Logger::log("CRITICAL DB error mapping property " . ($altoId ?? 'N/A') . ": " . $e->getMessage(), 'CRITICAL');
            return false;
        } catch (\Exception $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
                Logger::log("  Transaction rolled back for Alto " . ($altoId ?? 'N/A') . " due to Exception.", 'DEBUG');
            }
            Logger::log("CRITICAL general error mapping property " . ($altoId ?? 'N/A') . ": " . $e->getMessage(), 'CRITICAL');
            return false;
        }
    }
}
