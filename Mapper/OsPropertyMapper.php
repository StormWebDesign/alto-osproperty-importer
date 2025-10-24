<?php
// /public_html/cli/alto-sync/Mapper/OsPropertyMapper.php
// This file was originally xml_to_sql_mapper.php and has been moved/renamed.

namespace AltoSync\Mapper;

// Include the configuration file for database credentials and other settings
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Logger.php'; // Ensure Logger is available here

use AltoSync\Logger; // Use the Logger class
use AltoSync\Mapper\CategoryMapper;
use AltoSync\Mapper\BrochureMapper;


/**
 * Class OsPropertyMapper
 * Maps Alto XML data to the appropriate database tables for OS Property.
 */
class OsPropertyMapper
{

    private static $db = null;

    /**
     * Initializes the database connection.
     */
    private static function initDb()
    {
        if (self::$db === null) {
            try {
                self::$db = new \PDO(
                    'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
                    \DB_USER,
                    \DB_PASS,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
                // Ensure buffered queries for robustness against pending result sets
                self::$db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                // Explicitly set autocommit to true, though it's usually default
                self::$db->setAttribute(\PDO::ATTR_AUTOCOMMIT, true);
            } catch (\PDOException $e) {
                Logger::log("Database connection failed in OsPropertyMapper: " . $e->getMessage(), 'CRITICAL');
                // Use RuntimeException to allow caller to catch this critical error
                throw new \RuntimeException("Database connection failed in mapper.");
            }
        }
    }

    /**
     * Converts a string to a URL-safe slug.
     * Replaces JFilterOutput::stringURLSafe for Joomla 4/5 compatibility in CLI.
     * @param string $string The input string.
     * @return string The URL-safe slug.
     */
    private static function createSlug($string)
    {
        $string = strtolower(trim($string));
        // Replace non-alphanumeric characters (except hyphen) with hyphen
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        // Replace multiple hyphens with a single hyphen
        $string = trim($string, '-');
        return $string;
    }

    /**
     * Helper function to get or create lookup IDs in common tables (cities, countries, etc.).
     * @param \PDO $db The PDO database connection.
     * @param string $tableName The table name (e.g., 'osrs_cities').
     * @param string $nameColumn The column name for the value (e.g., 'city').
     * @param string $nameValue The value to lookup/insert (e.g., 'London').
     * @return int|false The ID of the existing or newly created record, or false on error/empty value.
     */
    public static function getOrCreateLookupId(\PDO $db, $tableName, $nameColumn, $nameValue)
    {
        $nameValue = \trim($nameValue);
        if ($nameValue === '') {
            Logger::log("Attempted to lookup/create " . $tableName . " with empty value for " . $nameColumn, 'WARNING');
            // For cities/states/countries, we can return 0 if empty/invalid to allow the property/company to be inserted.
            // OS Property might then show a generic "Unknown" or nothing for that field.
            return 0;
        }

        $prefixedTableName = \DB_PREFIX . $tableName;
        $nameColumn = \preg_replace('/[^a-zA-Z0-9_]/', '', $nameColumn); // Basic sanitization

        try {
            $stmt = $db->prepare("SELECT id FROM `" . $prefixedTableName . "` WHERE `" . $nameColumn . "` = ?");
            $stmt->execute([$nameValue]);
            $existingId = $stmt->fetchColumn();
            $stmt->closeCursor(); // Always close cursor
            unset($stmt); // Explicitly unset

            if ($existingId) {
                return (int)$existingId;
            } else {
                // For osrs_cities, osrs_states, osrs_countries, they have a 'published' column.
                // For other generic lookups, ensure the table structure matches.
                $stmt = $db->prepare("INSERT INTO `" . $prefixedTableName . "` (`" . $nameColumn . "`, published) VALUES (?, 1)");
                $stmt->execute([$nameValue]);
                $newId = $db->lastInsertId();
                unset($stmt); // Explicitly unset
                return (int)$newId;
            }
        } catch (\PDOException $e) {
            Logger::log("Database error in getOrCreateLookupId for " . $prefixedTableName . " (" . $nameValue . "): " . $e->getMessage(), 'ERROR');
            return false;
        }
    }


    /**
     * Gets or creates a Joomla user for the negotiator and returns their user ID.
     * @param \PDO $db The PDO database connection.
     * @param string $negotiatorId The Alto negotiator ID.
     * @param string $negotiatorName The negotiator's full name.
     * @param string $negotiatorEmail The negotiator's email address.
     * @param string $negotiatorPhone The negotiator's phone number.
     * @return int|false The Joomla user ID, or false on error/empty data.
     */
    public static function getOrCreateAgentId(\PDO $db, $negotiatorId, $negotiatorName, $negotiatorEmail, $negotiatorPhone)
    {
        $negotiatorEmail = \trim($negotiatorEmail); // Trim email first

        if ($negotiatorEmail === '' || !\filter_var($negotiatorEmail, FILTER_VALIDATE_EMAIL)) {
            Logger::log("Cannot create agent: Invalid or empty email provided for negotiator ID " . $negotiatorId, 'WARNING');
            return false;
        }

        // Check if an agent already exists by Alto ID (custom column in Joomla users table, 'alto_negotiator_id')
        $stmt = $db->prepare("SELECT id FROM `" . \DB_PREFIX . "users` WHERE `alto_negotiator_id` = ?");
        $stmt->execute([$negotiatorId]);
        $existingJoomlaUserId = $stmt->fetchColumn();
        $stmt->closeCursor(); // Always close cursor
        unset($stmt); // Explicitly unset

        if ($existingJoomlaUserId) {
            $stmt = $db->prepare("
                UPDATE `" . \DB_PREFIX . "users` SET
                    `name` = ?, `email` = ?, `modified` = NOW()
                WHERE `id` = ?
            ");
            $stmt->execute([$negotiatorName, $negotiatorEmail, $existingJoomlaUserId]);
            unset($stmt); // Explicitly unset
            Logger::log("    Updated existing agent (Joomla ID: " . $existingJoomlaUserId . ") for Alto Negotiator ID: " . $negotiatorId, 'INFO');
            return (int)$existingJoomlaUserId;
        } else {
            $username = \strtolower(\str_replace(' ', '', $negotiatorName)) . '_' . $negotiatorId;
            $username = \substr(\preg_replace('/[^a-z0-9]/', '', $username), 0, 150);
            $username = $username . '_' . \uniqid(); // Ensure uniqueness

            $password = \password_hash(\substr(\md5(\rand()), 0, 8), PASSWORD_DEFAULT);

            try {
                $stmt = $db->prepare("
                    INSERT INTO `" . \DB_PREFIX . "users` (
                        `name`, `username`, `email`, `password`, `registerDate`, `lastvisitDate`, `activation`,
                        `sendEmail`, `block`, `requireReset`, `alto_negotiator_id`
                    ) VALUES (
                        ?, ?, ?, ?, NOW(), '0000-00-00 00:00:00', '',
                        0, 0, 0, ?
                    )
                ");
                $stmt->execute([$negotiatorName, $username, $negotiatorEmail, $password, $negotiatorId]);
                $newJoomlaUserId = $db->lastInsertId();
                unset($stmt); // Explicitly unset

                if ($newJoomlaUserId) {
                    Logger::log("    Created new Joomla user (ID: " . $newJoomlaUserId . ") for Alto Negotiator ID: " . $negotiatorId, 'INFO');

                    $groupStmt = $db->prepare("INSERT IGNORE INTO `" . \DB_PREFIX . "user_usergroup_map` (user_id, group_id) VALUES (?, ?)");
                    $groupStmt->execute([$newJoomlaUserId, 2]);
                    unset($groupStmt); // Explicitly unset

                    return (int)$newJoomlaUserId;
                }
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) { // Integrity constraint violation (e.g., duplicate unique key)
                    Logger::log("    Attempted to create duplicate user for negotiator " . $negotiatorId . " (email: " . $negotiatorEmail . "): " . $e->getMessage(), 'WARNING');
                    $stmt = $db->prepare("SELECT id FROM `" . \DB_PREFIX . "users` WHERE `username` = ? OR `email` = ?");
                    $stmt->execute([$username, $negotiatorEmail]);
                    $existingId = $stmt->fetchColumn();
                    $stmt->closeCursor(); // Always close cursor
                    unset($stmt); // Explicitly unset
                    if ($existingId) {
                        Logger::log("    Found existing Joomla user ID " . $existingId . " for duplicate negotiator " . $negotiatorId, 'INFO');
                        return (int)$existingId;
                    }
                }
                Logger::log("Database error creating agent for Alto Negotiator ID " . $negotiatorId . ": " . $e->getMessage(), 'CRITICAL');
                return false;
            }
        }
        return false;
    }


    /**
     * Maps branch details from XML to the `#__osrs_companies` table.
     * @param \SimpleXMLElement $branchXmlObject A SimpleXMLElement object representing a single <branch> node.
     * @return bool True on success, false on failure.
     */
    public static function mapBranchDetailsToDatabase(\SimpleXMLElement $branchXmlObject)
    {
        self::initDb();

        try {
            $branchid = (string)$branchXmlObject->branchid;
            // Provide sensible defaults for potentially empty XML nodes
            $branchName = (string)$branchXmlObject->name ?: 'Branch ' . $branchid;
            $branchUrl = (string)$branchXmlObject->url;

            $addressLine1 = (string)$branchXmlObject->address->line1;
            $addressLine2 = (string)$branchXmlObject->address->line2;
            $addressLine3 = (string)$branchXmlObject->address->line3;
            $town = (string)$branchXmlObject->address->town;
            $postcode = (string)$branchXmlObject->address->postcode;
            $country = (string)$branchXmlObject->address->country ?: 'United Kingdom'; // Default country

            $email = (string)$branchXmlObject->email;
            $telephone = (string)$branchXmlObject->telephone;
            $fax = (string)$branchXmlObject->fax;
            $website = (string)$branchXmlObject->website;

            // Pass 0 if town/country is empty, as per getOrCreateLookupId returning 0 for empty values
            $cityId = self::getOrCreateLookupId(self::$db, 'osrs_cities', 'city', $town);
            $countryId = self::getOrCreateLookupId(self::$db, 'osrs_countries', 'country_name', $country);

            // OS Property's `address` field in `osrs_companies` typically stores a single string for address.
            // Concatenate address lines for this field.
            $fullAddress = \trim(\implode(', ', \array_filter([$addressLine1, $addressLine2, $addressLine3])));


            // Check if a company with this alto_branch_id already exists
            $stmt = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_companies` WHERE alto_branch_id = ?");
            $stmt->execute([$branchid]);
            $existingOsCompanyId = $stmt->fetchColumn();
            $stmt->closeCursor();
            unset($stmt);

            if ($existingOsCompanyId) {
                // If the company already exists (based on alto_branch_id), do NOT update it.
                // This preserves all manually entered data for that company.
                Logger::log("    Existing Branch (Company) ID " . $existingOsCompanyId . " for Alto Branch ID: " . $branchid . " found. Skipping update to preserve existing data.", 'INFO');
                return true; // Indicate success as no action is needed
            } else {
                // If the company does NOT exist, insert it.
                Logger::log("    Inserting new Branch (Company) for Alto Branch ID: " . $branchid . ".", 'INFO');
                $stmt = self::$db->prepare("
                    INSERT INTO `" . \DB_PREFIX . "osrs_companies` (
                        `alto_branch_id`, `company_name`, `company_alias`, `email`, `phone`, `fax`,
                        `address`, `city`, `country`, `website`, `postcode`, `published`, `user_id`
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, 1, 0
                    )
                ");
                $success = $stmt->execute([
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
                $newOsCompanyId = self::$db->lastInsertId();
                unset($stmt);
                if ($success) {
                    Logger::log("    New Branch (Company) ID " . $branchid . " inserted into " . \DB_PREFIX . "osrs_companies (OS Company ID: " . $newOsCompanyId . ").", 'INFO');
                } else {
                    Logger::log("    Failed to insert new branch (Company) ID " . $branchid . ": " . json_encode($stmt->errorInfo()), 'ERROR');
                }
                return $success;
            }
        } catch (\PDOException $e) {
            Logger::log("Database error mapping branch to osrs_companies: " . $e->getMessage(), 'ERROR');
            return false;
        } catch (\Exception $e) {
            Logger::log("General error mapping branch to osrs_companies: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Gets or creates the OS Property Type ID based on the Alto property type string.
     * This maps to the `#__osrs_types` table.
     *
     * @param \PDO $db The PDO database connection.
     * @param string $altoPropertyType The value from Alto's <type> tag (e.g., "House - Townhouse").
     * @return int The corresponding OS Property `pro_type` ID. Defaults to 7 (Unknown).
     */
    public static function getOrCreatePropertyTypeId(\PDO $db, string $altoPropertyType): int
    {
        $altoPropertyType = trim($altoPropertyType);
        Logger::log("  DEBUG: getOrCreatePropertyTypeId - Input: altoPropertyType='" . $altoPropertyType . "'", 'DEBUG');

        if (empty($altoPropertyType)) {
            Logger::log("  WARNING: Alto property <type> tag is empty. Defaulting to 'Unknown' Property Type (ID 7).", 'WARNING');
            return 7; // Unknown
        }

        // Map Alto types to a standardized set based on Rightmove schema
        $standardizedType = 'Other'; // Default if no specific match
        $altoTypeLower = strtolower($altoPropertyType);

        if (str_contains($altoTypeLower, 'house')) {
            $standardizedType = 'House';
        } elseif (str_contains($altoTypeLower, 'bungalow')) {
            $standardizedType = 'Bungalow';
        } elseif (str_contains($altoTypeLower, 'flat') || str_contains($altoTypeLower, 'apartment')) {
            $standardizedType = 'Flat';
        } elseif (str_contains($altoTypeLower, 'maisonette')) {
            $standardizedType = 'Maisonette';
        } elseif (str_contains($altoTypeLower, 'land')) {
            $standardizedType = 'Land';
        } elseif (str_contains($altoTypeLower, 'farm')) {
            $standardizedType = 'Farm';
        } elseif (str_contains($altoTypeLower, 'commercial')) {
            $standardizedType = 'Commercial';
        } elseif (str_contains($altoTypeLower, 'garage')) {
            $standardizedType = 'Garage';
        } elseif (str_contains($altoTypeLower, 'parking')) {
            $standardizedType = 'Parking';
        }

        Logger::log("  DEBUG: getOrCreatePropertyTypeId - Standardized type for '" . $altoPropertyType . "' is '" . $standardizedType . "'.", 'DEBUG');

        $prefixedTableName = \DB_PREFIX . "osrs_types";
        $nameColumn = "type_name"; // Column in osrs_types

        try {
            $stmt = $db->prepare("SELECT id FROM `" . $prefixedTableName . "` WHERE `" . $nameColumn . "` = ?");
            $stmt->execute([$standardizedType]);
            $existingId = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($existingId) {
                Logger::log("  DEBUG: getOrCreatePropertyTypeId - Found existing OS Property Type '" . $standardizedType . "' (ID " . $existingId . ").", 'DEBUG');
                return (int)$existingId;
            } else {
                $stmt = $db->prepare("INSERT INTO `" . $prefixedTableName . "` (`" . $nameColumn . "`, published) VALUES (?, 1)");
                $stmt->execute([$standardizedType]);
                $newId = $db->lastInsertId();
                Logger::log("  INFO: Created new OS Property Type '" . $standardizedType . "' (ID " . $newId . ").", 'INFO');
                return (int)$newId;
            }
        } catch (\PDOException $e) {
            Logger::log("  ERROR: Database error in getOrCreatePropertyTypeId for '" . $standardizedType . "': " . $e->getMessage(), 'ERROR');
            return 7; // Fallback to Unknown
        }
    }

    /**
     * Gets or creates the OS Property Category ID based on the Alto web_status.
     * This maps to the `#__osrs_categories` table.
     *
     * @param \PDO $db The PDO database connection.
     * @param string $altoWebStatus The value from Alto's <web_status> tag (e.g., "0", "For Sale", "Sold").
     * @return int The corresponding OS Property `category_id`. Defaults to 7 (Unknown).
     */
    public static function getOrCreateCategoryId(\PDO $db, string $altoWebStatus): int
    {
        $altoWebStatusTrimmed = trim($altoWebStatus);
        Logger::log("  DEBUG: getOrCreateCategoryId - Input: altoWebStatus='" . $altoWebStatusTrimmed . "'", 'DEBUG');

        if (empty($altoWebStatusTrimmed)) {
            Logger::log("  WARNING: Alto <web_status> tag is empty. Defaulting to 'Unknown' Category (ID 7).", 'WARNING');
            return 7; // Unknown
        }

        $categoryName = 'Unknown Status'; // Default category name
        $webStatusNumeric = is_numeric($altoWebStatusTrimmed) ? (int)$altoWebStatusTrimmed : null;

        // Prioritize numerical web_status mapping based on PDF
        if ($webStatusNumeric !== null) {
            switch ($webStatusNumeric) {
                case 0:
                    $categoryName = 'For Sale / To Let';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '0' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                case 1:
                    $categoryName = 'Let Agreed / Under Offer';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '1' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                case 2:
                    $categoryName = 'Let';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '2' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                case 3:
                    $categoryName = 'Withdrawn';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '3' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                case 4:
                    $categoryName = 'Completed';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '4' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                case 100:
                    $categoryName = 'To Let (New Lettings)';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '100' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                case 101:
                    $categoryName = 'Let Agreed (New Lettings)';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '101' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                case 102:
                    $categoryName = 'Let (New Lettings)';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '102' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                case 103:
                    $categoryName = 'Withdrawn (New Lettings)';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '103' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                case 104:
                    $categoryName = 'Completed (New Lettings)';
                    Logger::log("  DEBUG: getOrCreateCategoryId - Mapped numeric web_status '104' to category '" . $categoryName . "'.", 'DEBUG');
                    break;
                default:
                    $categoryName = 'Unknown Status ' . $webStatusNumeric; // Fallback for unknown numbers
                    Logger::log("  WARNING: Unknown numerical web_status '" . $webStatusNumeric . "'. Mapping to category '" . $categoryName . "'.", 'WARNING');
                    break;
            }
        } else { // Handle string web_status mapping
            $altoWebStatusLower = strtolower($altoWebStatusTrimmed);
            if (str_contains($altoWebStatusLower, 'sold')) {
                $categoryName = 'Sold';
                Logger::log("  DEBUG: getOrCreateCategoryId - Mapped string web_status '" . $altoWebStatusTrimmed . "' to category 'Sold'.", 'DEBUG');
            } elseif (str_contains($altoWebStatusLower, 'let')) { // Covers "to let", "let agreed"
                $categoryName = 'To Let'; // General "To Let" for string
                Logger::log("  DEBUG: getOrCreateCategoryId - Mapped string web_status '" . $altoWebStatusTrimmed . "' to category 'To Let'.", 'DEBUG');
            } elseif (str_contains($altoWebStatusLower, 'for sale')) {
                $categoryName = 'For Sale';
                Logger::log("  DEBUG: getOrCreateCategoryId - Mapped string web_status '" . $altoWebStatusTrimmed . "' to category 'For Sale'.", 'DEBUG');
            } elseif (str_contains($altoWebStatusLower, 'under offer')) {
                $categoryName = 'Under Offer';
                Logger::log("  DEBUG: getOrCreateCategoryId - Mapped string web_status '" . $altoWebStatusTrimmed . "' to category 'Under Offer'.", 'DEBUG');
            } elseif (str_contains($altoWebStatusLower, 'pending') || str_contains($altoWebStatusLower, 'stc')) { // Sold Subject To Contract
                $categoryName = 'Pending';
                Logger::log("  DEBUG: getOrCreateCategoryId - Mapped string web_status '" . $altoWebStatusTrimmed . "' to category 'Pending'.", 'DEBUG');
            } elseif (str_contains($altoWebStatusLower, 'available')) {
                $categoryName = 'Available';
                Logger::log("  DEBUG: getOrCreateCategoryId - Mapped string web_status '" . $altoWebStatusTrimmed . "' to category 'Available'.", 'DEBUG');
            } else {
                $categoryName = $altoWebStatusTrimmed; // Use the raw string if no specific mapping
                Logger::log("  WARNING: Unknown string web_status '" . $altoWebStatusTrimmed . "'. Attempting to create category with this name.", 'WARNING');
            }
        }

        // Now, get or create the category ID in osrs_categories
        $prefixedTableName = \DB_PREFIX . "osrs_categories";
        $nameColumn = "category_name"; // Column in osrs_categories

        try {
            $stmt = $db->prepare("SELECT id FROM `" . $prefixedTableName . "` WHERE `" . $nameColumn . "` = ?");
            $stmt->execute([$categoryName]);
            $existingId = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($existingId) {
                Logger::log("  DEBUG: getOrCreateCategoryId - Found existing OS Property Category '" . $categoryName . "' (ID " . $existingId . ").", 'DEBUG');
                return (int)$existingId;
            } else {
                $stmt = $db->prepare("INSERT INTO `" . $prefixedTableName . "` (`" . $nameColumn . "`, `category_alias`, published) VALUES (?, ?, 1)");
                $stmt->execute([$categoryName, self::createSlug($categoryName)]);
                $newId = $db->lastInsertId();
                Logger::log("  INFO: Created new OS Property Category '" . $categoryName . "' (ID " . $newId . ").", 'INFO');
                return (int)$newId;
            }
        } catch (\PDOException $e) {
            Logger::log("  ERROR: Database error in getOrCreateCategoryId for '" . $categoryName . "': " . $e->getMessage(), 'ERROR');
            return 7; // Fallback to Unknown
        }
    }

    /**
     * Do a quick HEAD to see if the URL is an image; returns content-type (e.g. "image/jpeg") or null.
     */
    private static function detectImageContentType(string $url, int $timeout = 10): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY        => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT       => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT     => 'AltoSync/1.0',
            CURLOPT_FAILONERROR   => false,
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (!$ok || $code >= 400) {
            return null;
        }
        if (is_string($ct) && str_starts_with(strtolower(trim($ct)), 'image/')) {
            return trim(strtolower($ct));
        }
        return null;
    }

    /** Map content-type to a safe file extension (defaults to 'jpg'). */
    private static function detectExtFromContentType(?string $ct): string
    {
        $ct = strtolower((string)$ct);
        return match (true) {
            str_contains($ct, 'image/jpeg'), str_contains($ct, 'image/jpg') => 'jpg',
            str_contains($ct, 'image/png')  => 'png',
            str_contains($ct, 'image/gif')  => 'gif',
            str_contains($ct, 'image/webp') => 'webp',
            default => 'jpg',
        };
    }


    /**
     * Decide whether a file node represents an image we should import.
     * Accept if:
     *  - type in {"0","1","image","photo"} OR missing, AND
     *  - URL/name has an image extension; OR server says Content-Type starts with image/.
     */
    private static function isProbablyImage(string $url, string $originalName = '', ?string $typeAttr = null): bool
    {
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $typeAttr = trim((string)$typeAttr);
        $urlPath  = parse_url($url, PHP_URL_PATH) ?: $url;
        $extUrl   = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        $extName  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $hasImgExt = in_array($extUrl, $allowedExt, true) || in_array($extName, $allowedExt, true);
        $typeSuggestsImage = ($typeAttr === '' || $typeAttr === null || in_array($typeAttr, ['0', '1', 'image', 'photo'], true));

        if ($typeSuggestsImage && $hasImgExt) {
            return true;
        }

        // Last resort: HEAD request to see if it is an image by Content-Type.
        if ($typeSuggestsImage) {
            $ct = self::detectImageContentType($url, 8);
            if ($ct !== null) {
                Logger::log("    HEAD says image Content-Type '{$ct}' for URL: {$url}", 'DEBUG');
                return true;
            }
        }

        return false;
    }

    private static function ensureImageBaseWritable(): bool
    {
        $base = rtrim(\PROPERTY_IMAGE_UPLOAD_BASE_PATH, '/') . '/';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        if (!is_dir($base)) {
            Logger::log("Image base dir does not exist: {$base}", 'CRITICAL');
            return false;
        }
        if (!is_writable($base)) {
            Logger::log("Image base dir not writable: {$base}", 'CRITICAL');
            return false;
        }
        return true;
    }



    /**
     * Downloads an image from a URL and saves it locally, then maps it to the database.
     *
     * @param string $imageUrl The URL of the image to download.
     * @param int $propertyOsId The OS Property ID to link the image to.
     * @param string $imageOriginalName The suggested original name for the image file from Alto.
     * @param int $ordering The display order of the image.
     * @param string $imageDescription A description for the image.
     * @return bool True on success, false on failure.
     */
    private static function downloadAndMapImage(
        string $imageUrl,
        int $propertyOsId,
        string $imageOriginalName,
        int $ordering,
        string $imageDescription = '',
        bool $isDefault = false
    ): bool {
        if (!self::ensureImageBaseWritable()) {
            return false;
        }

        // Create the property-specific directory if it doesn't exist
        $propertyImageDir = \PROPERTY_IMAGE_UPLOAD_BASE_PATH . $propertyOsId . '/';
        Logger::log("DEBUG: propertyImageDir = " . $propertyImageDir, 'DEBUG');

        if (!is_dir($propertyImageDir)) {
            if (!mkdir($propertyImageDir, 0755, true)) {
                Logger::log("        ERROR: Failed to create property image directory: " . $propertyImageDir, 'ERROR');
                return false;
            }
        }

        // Derive extension (prefer from name/url; if unknown, try HEAD content-type)
        $urlPath     = parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl;
        $extFromUrl  = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        $extFromName = strtolower(pathinfo($imageOriginalName, PATHINFO_EXTENSION));

        $extCandidates = array_filter([$extFromName, $extFromUrl]);
        $ext = '';
        foreach ($extCandidates as $cand) {
            if (in_array($cand, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $ext = $cand;
                break;
            }
        }
        if ($ext === '') {
            $ct = self::detectImageContentType($imageUrl, 8);
            $ext = self::detectExtFromContentType($ct); // default jpg if unknown
        }

        // Normalise oddities
        if ($ext === 'jpe') $ext = 'jpg';

        // Sanitize original image name & strip trailing ext (we add our final ext)
        $sanitizedBase = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $imageOriginalName);
        $sanitizedBase = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $sanitizedBase);

        // Ensure unique filename and size budget
        $base = $propertyOsId . uniqid() . '_' . $sanitizedBase;
        $max  = 240 - (strlen($ext) + 1);
        if (strlen($base) > $max) $base = substr($base, 0, $max);
        $safeFileName  = $base . '.' . $ext;

        $localFilePath = $propertyImageDir . $safeFileName;
        $dbImagePath   = $safeFileName;



        // Download original if needed
        $downloaded = false;
        if (file_exists($localFilePath) && (filesize($localFilePath) > 0)) {
            Logger::log("        Image already exists locally: " . $localFilePath . ". Skipping download.", 'INFO');
        } else {
            // Attempt to download the image
            $fp = @fopen($localFilePath, 'wb');
            if (!$fp) {
                Logger::log("        ERROR: Cannot write to $localFilePath", 'ERROR');
                return false;
            }
            $ch = curl_init($imageUrl);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'AltoSync/1.0',
                CURLOPT_FAILONERROR    => false,
            ]);
            $ok   = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if (!$ok || $code >= 400 || !filesize($localFilePath)) {
                @unlink($localFilePath);
                Logger::log("        ERROR: cURL download failed (HTTP $code) for $imageUrl. " . ($err ? "cURL: $err" : ""), 'ERROR');
                return false;
            }

            Logger::log("        Image downloaded and saved to: " . $localFilePath, 'INFO');
        }


        // Immediately generate thumb & medium variants (idempotent)
        self::ensureResizedVariants($propertyOsId, $safeFileName);

        // Insert or update the image record in ix3gf_osrs_photos
        try {
            // Check if row exists
            $stmtCheck = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_photos` WHERE pro_id = ? AND image = ?");
            $stmtCheck->execute([$propertyOsId, $dbImagePath]);
            $existingPhotoId = $stmtCheck->fetchColumn();
            $stmtCheck->closeCursor();
            unset($stmtCheck);

            if ($existingPhotoId) {
                $stmt = self::$db->prepare("
                    UPDATE `" . \DB_PREFIX . "osrs_photos`
                    SET image_desc = ?, ordering = ?
                    WHERE id = ?
                ");
                $stmt->execute([$imageDescription, $ordering, $existingPhotoId]);

                Logger::log("        Updated existing photo record (ID: " . $existingPhotoId . ") for property " . $propertyOsId . ".", 'INFO');
            } else {
                $stmt = self::$db->prepare("
                    INSERT INTO `" . \DB_PREFIX . "osrs_photos` (pro_id, image, image_desc, ordering)
                    VALUES (?, ?, ?, ?)
            ");
                $stmt->execute([$propertyOsId, $dbImagePath, $imageDescription, $ordering]);

                Logger::log("        Inserted new photo record (ID: " . self::$db->lastInsertId() . ") for property " . $propertyOsId . ".", 'INFO');
            }
            unset($stmt);
            return true;
        } catch (\PDOException $e) {
            Logger::log("        Database error mapping image for property " . $propertyOsId . ": " . $e->getMessage(), 'ERROR');
            return false;
        }
    }


    /**
     * Decide OS Property category_id from Alto XML.
     * 5 = UK Residential Sales, 6 = UK Residential Lettings, 7 = Commercial
     */
    private static function determineCategoryId(\SimpleXMLElement $p): int
    {
        // Core signals
        $dept = strtolower(trim((string)($p->department ?? $p->web_department ?? '')));
        $type = strtolower(trim((string)($p->type ?? '')));

        // web_status may carry a numeric code in the id attribute
        $wsAttr = (string)($p->web_status['id'] ?? '');
        $wsText = strtolower(trim((string)($p->web_status ?? '')));

        // Lettings hints in price/rent wording
        $qualifier = strtolower(trim((string)($p->price->qualifier ?? '')));
        $display   = strtolower(trim((string)($p->price->display_text ?? '')));

        // 1) Commercial overrides everything if department or type says so
        if (
            $dept === 'commercial' || str_contains($type, 'commercial')
            || str_contains($type, 'office') || str_contains($type, 'retail')
            || str_contains($type, 'industrial') || str_contains($type, 'warehouse')
            || str_contains($type, 'shop') || str_contains($type, 'restaurant') || str_contains($type, 'bar')
        ) {
            return 7;
        }

        // 2) Explicit department: lettings or sales
        if ($dept === 'lettings' || $dept === 'rental' || $dept === 'to let') {
            return 6;
        }
        if ($dept === 'sales' || $dept === 'for sale') {
            return 5;
        }

        // 3) web_status numeric IDs: 100–104 are the “new lettings” codes
        if ($wsAttr !== '' && ctype_digit($wsAttr)) {
            $n = (int)$wsAttr;
            if ($n >= 100 && $n <= 104) return 6; // lettings
            // 0–4 are sales pipeline; treat as sales
            if ($n >= 0 && $n <= 4)     return 5; // sales
        }

        // 4) web_status text (fallback)
        if (str_contains($wsText, 'let'))   return 6; // covers "to let", "let agreed"
        if (str_contains($wsText, 'sale'))  return 5;

        // 5) Price text hints at rent (pcm/pw)
        if (
            str_contains($qualifier, 'pcm') || str_contains($qualifier, 'pw')
            || str_contains($display, 'pcm') || str_contains($display, 'per week')
            || str_contains($display, 'per calendar month')
        ) {
            return 6;
        }

        // 6) Last resort: treat as sales
        return 5;
    }

    /**
     * Ensure ix3gf_osrs_property_categories reflects the single category we computed.
     * Strategy: remove any existing rows for this pid, then insert one link row.
     */
    private static function upsertPropertyCategoryLink(int $propertyOsId, int $categoryId): void
    {
        self::initDb();

        try {
            // Remove any old links for this property
            $stmtDel = self::$db->prepare("DELETE FROM `" . \DB_PREFIX . "osrs_property_categories` WHERE pid = ?");
            $stmtDel->execute([$propertyOsId]);
            $stmtDel->closeCursor();

            // Insert the current category link
            $stmtIns = self::$db->prepare("
                INSERT INTO `" . \DB_PREFIX . "osrs_property_categories` (pid, category_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)
            ");
            $stmtIns->execute([$propertyOsId, $categoryId]);
            $stmtIns->closeCursor();

            Logger::log("    Category link synced for property {$propertyOsId} -> category {$categoryId}", 'INFO');
        } catch (\PDOException $e) {
            Logger::log("    ERROR syncing category link for property {$propertyOsId}: " . $e->getMessage(), 'ERROR');
        }
    }


    /**
     * Get OS Property currency id by ISO code (e.g., 'GBP'). Creates it if missing.
     * Falls back to GBP if no/unknown code provided.
     */
    private static function getCurrencyIdByIso(string $isoOrEmpty): int
    {
        self::initDb();

        $code = strtoupper(trim($isoOrEmpty ?: 'GBP')); // default GBP

        try {
            // Try existing currency
            $stmt = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_currencies` WHERE UPPER(currency_code) = ?");
            $stmt->execute([$code]);
            $id = (int)$stmt->fetchColumn();
            $stmt->closeCursor();

            if ($id > 0) {
                return $id;
            }

            // If not found and it's GBP, try a few common aliases (defensive)
            if ($code === 'GBP') {
                $stmt = self::$db->query("SELECT id FROM `" . \DB_PREFIX . "osrs_currencies` WHERE UPPER(currency_code) IN ('GBP','UKP')");
                $id = (int)$stmt->fetchColumn();
                if ($id > 0) return $id;
            }

            // Create minimal currency row if truly missing (rare)
            $ins = self::$db->prepare("
            INSERT INTO `" . \DB_PREFIX . "osrs_currencies` (currency_name, currency_code, currency_symbol, published)
            VALUES (?, ?, ?, 1)
        ");
            // Reasonable defaults
            $name   = ($code === 'GBP') ? 'Pound Sterling' : $code;
            $symbol = ($code === 'GBP') ? '£' : $code;
            $ins->execute([$name, $code, $symbol]);
            return (int)self::$db->lastInsertId();
        } catch (\PDOException $e) {
            Logger::log("Currency lookup/insert failed for code '{$code}': " . $e->getMessage(), 'ERROR');
            return 1; // safe fallback; typical sites have GBP at 1
        }
    }

    /**
     * Internal: fetch OS Property ID by Alto ID (returns 0 if not found).
     */
    private static function getOsPropertyIdByAltoId(string $altoId): int
    {
        self::initDb();
        try {
            $stmt = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_properties` WHERE alto_id = ?");
            $stmt->execute([$altoId]);
            $pid = (int)($stmt->fetchColumn() ?: 0);
            $stmt->closeCursor();
            return $pid;
        } catch (\PDOException $e) {
            Logger::log("getOsPropertyIdByAltoId failed for alto_id={$altoId}: " . $e->getMessage(), 'ERROR');
            return 0;
        }
    }

    /**
     * Public helper for the importer:
     * Return true if this Alto property has *no* images recorded in DB and none on disk,
     * meaning we should force a details/images fetch even when the summary hash is unchanged.
     */
    public static function propertyNeedsImages(string $altoId): bool
    {
        $pid = self::getOsPropertyIdByAltoId($altoId);
        if ($pid <= 0) {
            // Not in OS Property yet → let the normal flow handle it; don't force based on images.
            return false;
        }

        // 1) DB check
        try {
            $stmt = self::$db->prepare("SELECT COUNT(*) FROM `" . \DB_PREFIX . "osrs_photos` WHERE pro_id = ?");
            $stmt->execute([$pid]);
            $photoCount = (int)$stmt->fetchColumn();
            $stmt->closeCursor();
            if ($photoCount > 0) {
                return false; // DB already has photos
            }
        } catch (\PDOException $e) {
            Logger::log("propertyNeedsImages DB check failed for alto_id={$altoId}: " . $e->getMessage(), 'ERROR');
            // Be conservative: if we can't check, don't force.
            return false;
        }

        // 2) Filesystem check
        $base = rtrim(\PROPERTY_IMAGE_UPLOAD_BASE_PATH, '/');
        $propDir   = $base . '/' . $pid . '/';
        $thumbDir  = $propDir . 'thumb/';
        $mediumDir = $propDir . 'medium/';

        $hasAny = function (string $dir): bool {
            if (!is_dir($dir)) return false;
            // look for any common image extensions
            $list = glob($dir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
            return is_array($list) && count($list) > 0;
        };

        if ($hasAny($propDir) || $hasAny($thumbDir) || $hasAny($mediumDir)) {
            return false; // some files already exist
        }

        // No DB rows and nothing on disk → we should backfill
        return true;
    }



    /** Cache image config so we only hit DB once per run */
    private static $imageCfg = null;

    /**
     * Read image sizes/quality from osrs_configuration (cached).
     * Falls back to sensible defaults if not found.
     */
    private static function getImageConfig(): array
    {
        if (self::$imageCfg !== null) {
            return self::$imageCfg;
        }

        self::initDb();
        $cfg = [
            'thumb_w' => 170,
            'thumb_h' => 110,
            'large_w' => 600,
            'large_h' => 370,
            'quality' => 90,
        ];

        try {
            $stmt = self::$db->query("
                SELECT fieldname, fieldvalue
                FROM `" . \DB_PREFIX . "osrs_configuration`
                WHERE fieldname IN (
                    'images_thumbnail_width','images_thumbnail_height',
                    'images_large_width','images_large_height','images_quality'
                )
            ");
            $map = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $map[$row['fieldname']] = (int)$row['fieldvalue'];
            }
            $cfg['thumb_w'] = $map['images_thumbnail_width']  ?? $cfg['thumb_w'];
            $cfg['thumb_h'] = $map['images_thumbnail_height'] ?? $cfg['thumb_h'];
            $cfg['large_w'] = $map['images_large_width']      ?? $cfg['large_w'];
            $cfg['large_h'] = $map['images_large_height']     ?? $cfg['large_h'];
            $q = $map['images_quality'] ?? $cfg['quality'];
            $cfg['quality'] = max(1, min(100, (int)$q));
        } catch (\Throwable $e) {
            Logger::log("Image config load failed; using defaults. " . $e->getMessage(), 'WARNING');
        }

        self::$imageCfg = $cfg;
        return $cfg;
    }

    /**
     * Ensure thumb & medium variants exist for a given saved filename.
     * Keeps same filename, writes into /{pid}/thumb and /{pid}/medium.
     */
    private static function ensureResizedVariants(int $propertyOsId, string $fileName): void
    {
        $cfg = self::getImageConfig();

        $propDir   = \PROPERTY_IMAGE_UPLOAD_BASE_PATH . $propertyOsId . '/';
        $src       = $propDir . $fileName;

        // guard: only act on files that exist
        if (!is_file($src)) {
            Logger::log("    Skipping resize; source not found: {$src}", 'WARNING');
            return;
        }

        // Ensure subfolders
        $thumbDir  = $propDir . 'thumb/';
        $mediumDir = $propDir . 'medium/';
        if (!is_dir($thumbDir))  @mkdir($thumbDir, 0755, true);
        if (!is_dir($mediumDir)) @mkdir($mediumDir, 0755, true);

        $dstThumb  = $thumbDir  . $fileName;
        $dstMedium = $mediumDir . $fileName;

        $srcMTime = filemtime($src) ?: time();

        // Create/refresh thumb
        if (!file_exists($dstThumb) || (filemtime($dstThumb) ?: 0) < $srcMTime) {
            $ok = self::resizeOne($src, $dstThumb, $cfg['thumb_w'], $cfg['thumb_h'], $cfg['quality']);
            Logger::log($ok
                ? "        Wrote thumb: {$dstThumb}"
                : "        FAILED to write thumb: {$dstThumb}", $ok ? 'INFO' : 'ERROR');
        }

        // Create/refresh medium
        if (!file_exists($dstMedium) || (filemtime($dstMedium) ?: 0) < $srcMTime) {
            $ok = self::resizeOne($src, $dstMedium, $cfg['large_w'], $cfg['large_h'], $cfg['quality']);
            Logger::log($ok
                ? "        Wrote medium: {$dstMedium}"
                : "        FAILED to write medium: {$dstMedium}", $ok ? 'INFO' : 'ERROR');
        }
    }

    /**
     * Resize preserving aspect ratio; don't upscale; keep transparency for PNG/GIF.
     * Corrects EXIF orientation on JPEG. Returns true on success.
     */
    private static function resizeOne(string $src, string $dst, int $targetW, int $targetH, int $quality): bool
    {
        try {
            if ($targetW <= 0 || $targetH <= 0) {
                Logger::log("Invalid target size {$targetW}x{$targetH} for {$src}", 'ERROR');
                return false;
            }

            [$w, $h] = @getimagesize($src) ?: [0, 0];
            if ($w <= 0 || $h <= 0) {
                Logger::log("getimagesize failed for {$src}", 'ERROR');
                return false;
            }

            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $im = @imagecreatefromjpeg($src);
                    // Try EXIF orientation fix
                    if (function_exists('exif_read_data')) {
                        $exif = @exif_read_data($src);
                        if ($exif && !empty($exif['Orientation'])) {
                            switch ((int)$exif['Orientation']) {
                                case 3:
                                    $im = imagerotate($im, 180, 0);
                                    break;
                                case 6:
                                    $im = imagerotate($im, -90, 0);
                                    break;
                                case 8:
                                    $im = imagerotate($im, 90, 0);
                                    break;
                            }
                        }
                    }
                    break;
                case 'png':
                    $im = @imagecreatefrompng($src);
                    break;
                case 'gif':
                    $im = @imagecreatefromgif($src);
                    break;
                default:
                    return false;
            }
            if (!$im) return false;

            // contain-fit; don't upscale
            $scale = min($targetW / $w, $targetH / $h, 1.0);
            $newW  = max(1, (int)floor($w * $scale));
            $newH  = max(1, (int)floor($h * $scale));

            $dstIm = imagecreatetruecolor($newW, $newH);
            if ($ext === 'png') {
                imagealphablending($dstIm, false);
                imagesavealpha($dstIm, true);
                $transparent = imagecolorallocatealpha($dstIm, 0, 0, 0, 127);
                imagefilledrectangle($dstIm, 0, 0, $newW, $newH, $transparent);
            } elseif ($ext === 'gif') {
                $transIndex = imagecolortransparent($im);
                if ($transIndex >= 0) {
                    $transColor = imagecolorsforindex($im, $transIndex);
                    $transIndexNew = imagecolorallocate($dstIm, $transColor['red'], $transColor['green'], $transColor['blue']);
                    imagefill($dstIm, 0, 0, $transIndexNew);
                    imagecolortransparent($dstIm, $transIndexNew);
                }
            }

            if (!imagecopyresampled($dstIm, $im, 0, 0, 0, 0, $newW, $newH, $w, $h)) {
                imagedestroy($im);
                imagedestroy($dstIm);
                return false;
            }

            @mkdir(dirname($dst), 0755, true);

            $ok = false;
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $ok = imagejpeg($dstIm, $dst, $quality);
                    break;
                case 'png':
                    $compression = 9 - (int)round(($quality / 100) * 9); // map 0–100 → 9–0
                    $ok = imagepng($dstIm, $dst, $compression);
                    break;
                case 'gif':
                    $ok = imagegif($dstIm, $dst);
                    break;
            }

            imagedestroy($im);
            imagedestroy($dstIm);

            if ($ok) {
                @touch($dst, filemtime($src)); // keep mtime aligned
            }
            return $ok;
        } catch (\Throwable $e) {
            Logger::log("Resize error for {$src} -> {$dst}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /** 
     * Collect image candidates from either <files><file>… or <images><image>…
     * Returns an array of ['url' => string, 'name' => string, 'caption' => string]
     */
    private static function collectImageCandidates(\SimpleXMLElement $p): array
    {
        $out = [];

        // 1) Newer logic: <files><file>
        if (isset($p->files) && $p->files->file) {
            foreach ($p->files->file as $fileNode) {
                $typeAttr = isset($fileNode['type']) ? (string)$fileNode['type'] : null;
                $url      = (string)($fileNode->url ?? '');
                $name     = (string)($fileNode->name ?? '');
                $caption  = (string)($fileNode->caption ?? '');

                if ($url !== '' && self::isProbablyImage($url, $name, $typeAttr)) {
                    $out[] = ['url' => $url, 'name' => $name, 'caption' => $caption];
                }
            }
        }

        // 2) Fallback: <images><image>
        // Only use this if we didn't find anything in <files>
        if (empty($out) && isset($p->images) && $p->images->image) {
            foreach ($p->images->image as $imageNode) {
                // Common shapes: <image><url>, <caption>
                // Some feeds also provide <large_url> or attributes — prefer largest available
                $url = (string)($imageNode->large_url ?? $imageNode->url ?? '');
                $name = (string)($imageNode->name ?? basename((string)$url));
                $caption = (string)($imageNode->caption ?? '');

                if ($url !== '' && self::isProbablyImage($url, $name, null)) {
                    $out[] = ['url' => $url, 'name' => $name, 'caption' => $caption];
                }
            }
        }

        return $out;
    }


    /**
     * Maps property details from XML (SimpleXMLElement object) to database tables.
     * @param \SimpleXMLElement $propertyXmlObject A SimpleXMLElement object for a single property.
     * @param string $altoBranchId The Alto branch ID this property belongs to (from alto_properties table).
     * @return bool True on success, false on failure.
     */
    public static function mapPropertyDetailsToDatabase(\SimpleXMLElement $propertyXmlObject, $altoBranchId)
    {
        self::initDb();
        Logger::log("  Starting mapping for property XML.", 'INFO');

        // Start a transaction for this property mapping
        self::$db->beginTransaction();
        $success = false; // Assume failure until committed
        $propertyOsId = null; // Initialize property OS ID

        try {
            $altoId = (string)$propertyXmlObject->attributes()->id;
            if (\trim($altoId) === '' && isset($propertyXmlObject->prop_id)) {
                $altoId = (string)$propertyXmlObject->prop_id;
            }
            if (\trim($altoId) === '') {
                Logger::log("  ERROR: Alto Property ID not found in the full property XML object. Cannot map property.", 'ERROR');
                self::$db->rollBack(); // Rollback on error
                return false;
            }

            Logger::log("  Processing Alto Property ID: " . $altoId, 'INFO');

            // Default empty/invalid values to avoid errors
            $webStatus = (string)(
                $propertyXmlObject->web_status['id']    // prefer numeric attribute if present
                ?? $propertyXmlObject->web_status
                ?? 'available'
            );




            $propertyTypeAlto = (string)$propertyXmlObject->type ?: 'Unknown'; // Now using <type> for OS Property Type
            $bedrooms = (int)$propertyXmlObject->bedrooms;
            // Bathrooms are decimal(4,2) in DB, cast to float from Alto's int
            $bathrooms = (float)$propertyXmlObject->bathrooms;
            $receptions = (int)$propertyXmlObject->receptions;

            // Address components for OS Property `#__osrs_properties` table
            $addressName = (string)$propertyXmlObject->address->name;
            $addressStreet = (string)$propertyXmlObject->address->street;
            $addressLocality = (string)$propertyXmlObject->address->locality;
            $town = (string)$propertyXmlObject->address->town;
            $county = (string)$propertyXmlObject->address->county;
            $postcode = (string)$propertyXmlObject->address->postcode;
            $country = (string)$propertyXmlObject->country ?: 'United Kingdom';

            // Use <display> tag for pro_name (property title)
            $propertyTitle = (string)$propertyXmlObject->address->display ?: \trim(\implode(', ', \array_filter([$addressName, $addressStreet, $addressLocality])));
            if (empty($propertyTitle)) {
                $propertyTitle = "Property ID: " . $altoId; // Fallback title
            }
            // Use concatenated address for the 'address' column
            $concatAddress = \trim(\implode(', ', \array_filter([$addressName, $addressStreet, $addressLocality])));


            $priceRaw       = (string)($propertyXmlObject->price->value ?? $propertyXmlObject->price ?? '');
            $priceValue     = (int)preg_replace('/[^\d]/', '', $priceRaw); // numbers only
            $priceQualifier = (string)($propertyXmlObject->price->qualifier ?? '');
            $displayText    = (string)($propertyXmlObject->price->display_text ?? '');

            $summary     = (string)($propertyXmlObject->summary ?? '');
            $description = (string)($propertyXmlObject->description ?? '');
            // Fallback: build a short summary from full description if summary is missing
            if ($summary === '' && $description !== '') {
                $summary = mb_substr(trim(strip_tags($description)), 0, 300);
            }

            // Dates are DATE type, so format as Y-m-d
            $dateAdded = (string)$propertyXmlObject->date_added;
            $dateLastModified = (string)$propertyXmlObject->date_last_modified;

            $tenure = (string)$propertyXmlObject->tenure;
            $yearBuilt = (int)$propertyXmlObject->year_built;

            $epcCurrentEnergyEfficiency = (string)$propertyXmlObject->epc->current_energy_efficiency;
            $epcCurrentEnvironmentalImpact = (string)$propertyXmlObject->epc->current_environmental_impact;

            $totalFloorAreaSqft = (float)$propertyXmlObject->floor_area->total_floor_area_sqft;
            $totalLandAreaSqft = (float)$propertyXmlObject->land_area->total_land_area_sqft;

            // Agent and Company IDs are now hardcoded as requested
            $agentUserId = 1; // Hardcoded to 1 as requested
            $companyJoomlaId = 0; // Hardcoded to 0 as requested

            $latitude = (string)$propertyXmlObject->latitude;
            $longitude = (string)$propertyXmlObject->longitude;

            // Lookup IDs for OS Property specific tables
            $cityId = self::getOrCreateLookupId(self::$db, 'osrs_cities', 'city', $town);
            $stateId = self::getOrCreateLookupId(self::$db, 'osrs_states', 'state_name', $county);
            $countryId = self::getOrCreateLookupId(self::$db, 'osrs_countries', 'country_name', $country);

            // Use the new functions to get the correct pro_type and category_id
            // $propertyTypeId = self::getOrCreatePropertyTypeId(self::$db, $propertyTypeAlto);
            // Map to your fixed OS Property categories (5/6/7)
            // $categoryId = self::determineCategoryId($propertyXmlObject);
            // Replace the above three lines with the following:

            // Use the new functions to get the correct pro_type and category_id
            $propertyTypeId = self::getOrCreatePropertyTypeId(self::$db, $propertyTypeAlto);

            // --- CATEGORY MAPPING (Alto → OS Property) ---
            $market   = (string)($propertyXmlObject->marketing->market ?? $propertyXmlObject->market ?? $propertyXmlObject->department ?? '');
            $category = (string)($propertyXmlObject->marketing->category ?? $propertyXmlObject->category ?? $propertyXmlObject->type ?? '');

            // If no market provided, infer it from web_status (Alto v13 convention)
            if (empty($market) && isset($propertyXmlObject->web_status)) {
                $webStatus = (int)$propertyXmlObject->web_status;
                if ($webStatus >= 100 && $webStatus < 200) {
                    $market = 'To Let';
                } elseif ($webStatus >= 200 && $webStatus < 300) {
                    $market = 'For Sale';
                }
                Logger::log("  Inferred market '{$market}' from web_status {$webStatus}", 'DEBUG');
            }

            // Run through CategoryMapper
            Logger::log("  Proceeding to CategoryMapper with Market='{$market}' and Category='{$category}'", 'DEBUG');

            $newCategoryId = CategoryMapper::toOsCategoryId($market, $category);

            // Log exactly what the mapper attempted
            Logger::log("  CategoryMapper input → Market='{$market}', Category='{$category}'", 'DEBUG');

            // Apply result with fallback
            if ($newCategoryId !== null) {
                $categoryId = $newCategoryId;
                Logger::log("  ✅ CategoryMapper mapped '{$market}' + '{$category}' → OS Property Category ID {$categoryId}", 'INFO');
            } else {
                $categoryId = self::determineCategoryId($propertyXmlObject);
                Logger::log("  ⚠️ CategoryMapper returned null; using legacy determineCategoryId() → Category ID {$categoryId}", 'INFO');
            }

            // Summary line for this property
            Logger::log("  🏁 Completed mapping for property {$propertyXmlObject->id} → type_id={$propertyTypeId}, category_id={$categoryId}", 'INFO');



            // Determine OS Property status (published field) based on Alto web_status
            $osPropertyStatus = 1; // Default to Published (1)
            $webStatusLower = strtolower(trim($webStatus));
            $webStatusNumeric = is_numeric($webStatus) ? (int)$webStatus : null;

            Logger::log("  DEBUG: Determining published status for Alto ID " . $altoId . " - web_status: '" . $webStatus . "'", 'DEBUG');

            if ($webStatusNumeric !== null) {
                switch ($webStatusNumeric) {
                    case 0: // To Let / For Sale
                    case 100: // To Let (New Lettings)
                        $osPropertyStatus = 1; // Published
                        Logger::log("  DEBUG: Mapped numeric web_status '" . $webStatus . "' to Published (1) for published status.", 'DEBUG');
                        break;
                    case 1: // Let Agreed / Under Offer
                    case 2: // Let
                    case 3: // Withdrawn
                    case 4: // Completed
                    case 101: // Let Agreed (New Lettings)
                    case 102: // Let (New Lettings)
                    case 103: // Withdrawn (New Lettings)
                    case 104: // Completed (New Lettings)
                        $osPropertyStatus = 0; // Unpublished
                        Logger::log("  DEBUG: Mapped numeric web_status '" . $webStatus . "' to Unpublished (0) for published status.", 'DEBUG');
                        break;
                    default:
                        $osPropertyStatus = 1; // Default to published if unknown numeric status
                        Logger::log("  WARNING: Unknown numeric web_status for property " . $altoId . ": " . $webStatus . ". Defaulting to published (1) for published status.", 'WARNING');
                        break;
                }
            } else { // Handle string web_status
                switch ($webStatusLower) {
                    case 'for sale':
                    case 'to let':
                    case 'available':
                        $osPropertyStatus = 1; // Published
                        Logger::log("  DEBUG: Mapped string web_status '" . $webStatus . "' to Published (1) for published status.", 'DEBUG');
                        break;
                    case 'sold':
                    case 'let':
                    case 'under offer':
                    case 'sstc':
                    case 'stc':
                    case 'withdrawn':
                    case 'potential':
                        $osPropertyStatus = 0; // Unpublished/Archived in OS Property
                        Logger::log("  DEBUG: Mapped string web_status '" . $webStatus . "' to Unpublished (0) for published status.", 'DEBUG');
                        break;
                    default:
                        $osPropertyStatus = 1; // Default to published if unknown string status
                        Logger::log("  WARNING: Unknown string web_status for property " . $altoId . ": " . $webStatus . ". Defaulting to published (1) for published status.", 'WARNING');
                        break;
                }
            }

            // Prepare dates for MySQL (convertRIBUTES-MM-DDTHH:MM:SS.ms toRIBUTES-MM-DD) as 'created' and 'modified' are DATE type
            $createdDate = ($dateAdded ? \date('Y-m-d', \strtotime($dateAdded)) : \date('Y-m-d'));
            $modifiedDate = ($dateLastModified ? \date('Y-m-d', \strtotime($dateLastModified)) : \date('Y-m-d'));

            // Try multiple feed shapes for currency; default to GBP
            $currencyFromAttr = (string)($propertyXmlObject->price['currency_code'] ?? '');
            $currencyFromNode = (string)($propertyXmlObject->price->currency ?? '');
            $currencyIso = $currencyFromAttr ?: $currencyFromNode ?: 'GBP';
            $currencyId = self::getCurrencyIdByIso($currencyIso);


            // The `ref` column is for the property reference. This is where Alto's prop_id (altoId) should go.
            $propertyRef = (string)$altoId;

            // --- PDF File Mapping via BrochureMapper ---
            $pdfData = BrochureMapper::map($propertyXmlObject);

            // These fields are returned as an associative array matching OS Property schema:
            // ['pro_pdf_file', 'pro_pdf_file1', ..., 'pro_pdf_file9']
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

            Logger::log("  ✅ BrochureMapper integrated. Primary brochure: {$proPdfFile}", 'DEBUG');


            // Parameters for the INSERT ... ON DUPLICATE KEY UPDATE statement
            $params = [
                $altoId,
                $propertyTitle,
                $propertyTypeId,
                self::createSlug($propertyTitle),
                $concatAddress, // This is for the 'address' column
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
                0,
                1,
                1, // These are for INSERT part only (hits, approved, access)
                $createdDate,
                $modifiedDate, // These are for INSERT part only
                $companyJoomlaId,
                $categoryId, // Using categoryId for category_id column
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
                $proPdfFile9
            ];

            $sql = "
                INSERT INTO `" . \DB_PREFIX . "osrs_properties` (
                    `alto_id`, `pro_name`, `pro_type`, `pro_alias`, `address`,
                    `country`, `state`, `city`, `postcode`, `pro_small_desc`, `pro_full_desc`,
                    `price`, `price_text`, `curr`, `bed_room`, `bath_room`, `rooms`,
                    `lat_add`, `long_add`, `ref`, `agent_id`, `published`, `hits`, `approved`, `access`,
                    `created`, `modified`, `company_id`, `category_id`, -- Corrected column name here
                    `square_feet`, `lot_size`, `built_on`,
                    `pro_pdf_file`, `pro_pdf_file1`, `pro_pdf_file2`, `pro_pdf_file3`, `pro_pdf_file4`,
                    `pro_pdf_file5`, `pro_pdf_file6`, `pro_pdf_file7`, `pro_pdf_file8`, `pro_pdf_file9`
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
                    `pro_name` = VALUES(`pro_name`),
                    `pro_type` = VALUES(`pro_type`),
                    `pro_alias` = VALUES(`pro_alias`),
                    `address` = VALUES(`address`),
                    `country` = VALUES(`country`),
                    `state` = VALUES(`state`),
                    `city` = VALUES(`city`),
                    `postcode` = VALUES(`postcode`),
                    `pro_small_desc` = VALUES(`pro_small_desc`),
                    `pro_full_desc` = VALUES(`pro_full_desc`),
                    `price` = VALUES(`price`),
                    `price_text` = VALUES(`price_text`),
                    `curr` = VALUES(`curr`),
                    `bed_room` = VALUES(`bed_room`),
                    `bath_room` = VALUES(`bath_room`),
                    `rooms` = VALUES(`rooms`),
                    `lat_add` = VALUES(`lat_add`),
                    `long_add` = VALUES(`long_add`),
                    `ref` = VALUES(`ref`),
                    `agent_id` = VALUES(`agent_id`),
                    `published` = VALUES(`published`),
                    `modified` = VALUES(`modified`),
                    `company_id` = VALUES(`company_id`),
                    `category_id` = VALUES(`category_id`), -- Corrected column name here
                    `square_feet` = VALUES(`square_feet`),
                    `lot_size` = VALUES(`lot_size`),
                    `built_on` = VALUES(`built_on`),
                    `pro_pdf_file` = VALUES(`pro_pdf_file`),
                    `pro_pdf_file1` = VALUES(`pro_pdf_file1`),
                    `pro_pdf_file2` = VALUES(`pro_pdf_file2`),
                    `pro_pdf_file3` = VALUES(`pro_pdf_file3`),
                    `pro_pdf_file4` = VALUES(`pro_pdf_file4`),
                    `pro_pdf_file5` = VALUES(`pro_pdf_file5`),
                    `pro_pdf_file6` = VALUES(`pro_pdf_file6`),
                    `pro_pdf_file7` = VALUES(`pro_pdf_file7`),
                    `pro_pdf_file8` = VALUES(`pro_pdf_file8`),
                    `pro_pdf_file9` = VALUES(`pro_pdf_file9`),
                    `hits` = `hits` -- Preserve hits on update
            ";

            Logger::log("  DEBUG: Property SQL Query: " . preg_replace('/\s+/', ' ', $sql), 'DEBUG'); // Log SQL
            Logger::log("  DEBUG: Property SQL Params: " . json_encode($params), 'DEBUG'); // Log Parameters

            $stmt = self::$db->prepare($sql);
            $execSuccess = $stmt->execute($params);

            if ($execSuccess) {
                $rowCount = $stmt->rowCount();
                Logger::log("  DEBUG: PDO rowCount for property " . $altoId . ": " . $rowCount, 'DEBUG');

                if ($rowCount == 1) {
                    $propertyOsId = self::$db->lastInsertId();
                    Logger::log("  New OS Property created with ID: " . $propertyOsId . " for Alto ID: " . $altoId . ".", 'INFO');
                } else if ($rowCount == 2) {
                    // For updates, we need to get the existing property ID to link images
                    $stmtGetId = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_properties` WHERE alto_id = ?");
                    $stmtGetId->execute([$altoId]);
                    $propertyOsId = $stmtGetId->fetchColumn();
                    $stmtGetId->closeCursor();
                    unset($stmtGetId);
                    Logger::log("  Existing OS Property updated for Alto ID: " . $altoId . ". OS Property ID: " . $propertyOsId, 'INFO');
                } else {
                    // For updates with no changes, still need the property ID for images
                    $stmtGetId = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_properties` WHERE alto_id = ?");
                    $stmtGetId->execute([$altoId]);
                    $propertyOsId = $stmtGetId->fetchColumn();
                    $stmtGetId->closeCursor();
                    unset($stmtGetId);
                    Logger::log("  Existing OS Property for Alto ID: " . $altoId . " already up-to-date (no changes). OS Property ID: " . $propertyOsId, 'INFO');
                }
                $success = true;

                // Keep the join table in sync with the single computed category
                if ($propertyOsId) {
                    self::upsertPropertyCategoryLink((int)$propertyOsId, (int)$categoryId);
                }
            } else {
                Logger::log("  Failed to insert/update property " . $altoId . ": " . json_encode($stmt->errorInfo()), 'ERROR');
                $success = false;
            }
            unset($stmt);

            // --- Process Images (supports <files> and <images>) ---
            if ($success && $propertyOsId) {
                $candidates = self::collectImageCandidates($propertyXmlObject);

                Logger::log("    Image candidates found: " . count($candidates) . " for Alto {$altoId}.", 'DEBUG');

                $ordering = 0;
                foreach ($candidates as $idx => $img) {
                    $isDefault = ($idx === 0);
                    $ok = self::downloadAndMapImage(
                        $img['url'],
                        (int)$propertyOsId,
                        (string)$img['name'],
                        $ordering,
                        (string)$img['caption'],
                        $isDefault
                    );
                    if ($ok) {
                        $ordering++;
                    } else {
                        Logger::log("    ERROR processing image for Alto {$altoId}: {$img['url']}", 'ERROR');
                    }
                }

                if ($ordering === 0) {
                    Logger::log("    No importable images detected for property {$altoId}.", 'INFO');
                }
            }



            // Process features (placeholder for now) - This logic will need to be expanded
            if (isset($propertyXmlObject->features) && $propertyXmlObject->features->feature) {
                foreach ($propertyXmlObject->features->feature as $feature) {
                    $featureText = (string)$feature;
                    Logger::log("    Feature found for property " . $altoId . ": " . $featureText, 'INFO');
                    // TODO: Implement feature mapping to #__osrs_property_fields or other relevant tables
                }
            }

            // Commit the transaction if all operations were successful
            if (self::$db->inTransaction()) {
                if ($success) {
                    self::$db->commit();
                    Logger::log("  Transaction committed for property " . $altoId, 'DEBUG');
                } else {
                    self::$db->rollBack();
                    Logger::log("  Transaction rolled back for property " . $altoId . " due to failure.", 'DEBUG');
                }
            }
            return $success;
        } catch (\PDOException $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
                Logger::log("  Transaction rolled back for property " . ($altoId ?? 'N/A') . " due to PDOException.", 'DEBUG');
            }
            Logger::log("Database error mapping property " . ($altoId ?? 'N/A') . ": " . $e->getMessage(), 'CRITICAL');
            return false;
        } catch (\Exception $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
                Logger::log("  Transaction rolled back for property " . ($altoId ?? 'N/A') . " due to General Exception.", 'DEBUG');
            }
            Logger::log("General error mapping property " . ($altoId ?? 'N/A') . ": " . $e->getMessage(), 'CRITICAL');
            return false;
        }
    }
}
