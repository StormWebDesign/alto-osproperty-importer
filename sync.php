<?php
// /public_html/cli/alto-sync/sync.php - Main script for Alto data synchronization

namespace AltoSync; // Declare namespace for this script

// Include necessary files. Note: __DIR__ ensures correct paths relative to this script.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';        // Logger utility class
require_once __DIR__ . '/AltoApi.php';        // Alto API communication class
require_once __DIR__ . '/Mapper/OsPropertyMapper.php'; // OS Property mapping logic

// Use statements to import classes into the current namespace
use AltoSync\Logger;
use AltoSync\AltoApi;
use AltoSync\Mapper\OsPropertyMapper;

/**
 * Syncs Alto data with the database.
 */
class AltoDataSynchronizer
{
    private $db; // PDO connection
    private $altoApi; // Instance of AltoApi

    // The mapper instance is not needed as a class property here
    // because OsPropertyMapper methods are now static and handle their own DB init.

    private $datafeedId;
    private $apiVersion = 'v13';

    public function __construct()
    {
        $this->datafeedId = ALTO_API_DATAFEED_ID;

        // Direct PDO connection using constants from config.php
        try {
            $this->db = new \PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $this->db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); // Ensure buffered queries
            Logger::log('Database connection established for alto-sync.', 'INFO');
        } catch (\PDOException $e) {
            Logger::log('Database connection failed in sync.php: ' . $e->getMessage(), 'CRITICAL');
            die("Database connection failed.");
        }

        $this->altoApi = new AltoApi(TOKEN_FILE); // Pass token file path to AltoApi
        // No direct instantiation of OsPropertyMapper here if its methods are static
    }

    public function runSynchronization()
    {
        Logger::log('------------------------------------------------------------------------');
        Logger::log('Alto Data Synchronization started: ' . date('Y-m-d H:i:s T'));
        Logger::log('------------------------------------------------------------------------');
        Logger::log('');

        Logger::log('Starting Alto Data Synchronization...', 'INFO');

        // Check for required tables
        Logger::log("    Checking for required database tables...", 'INFO');
        $this->checkAndCreateTables();

        // Ensure we have a valid token before proceeding with API calls
        $accessToken = $this->altoApi->getAccessToken();
        if (!$accessToken) {
            Logger::log("    No valid OAuth token found. Ensure get_token.php has been run and tokens.txt exists with a valid token.", 'CRITICAL');
            Logger::log("    Aborting synchronization.", 'CRITICAL');
            return; // Stop if we can't get a token
        } else {
            Logger::log("    Using existing OAuth token for API calls.", 'INFO');
        }

        // 1. Fetching branches list
        Logger::log("1. Fetching branches list...", 'INFO');
        $branchXmlResponse = $this->altoApi->fetchBranchList(); // Fetch the full XML response

        if ($branchXmlResponse) {
            Logger::log("    API call to branch list successful.", 'INFO');
            $this->processBranches($branchXmlResponse); // Pass the full XML response to processBranches
        } else {
            Logger::log("    Failed to retrieve branches XML from Alto API. Check API connection and token.", 'ERROR');
        }

        // 2. Fetching property summaries for each branch
        Logger::log("2. Fetching property summaries for each branch...", 'INFO');
        $this->processPropertyListingsForAllBranches();

        // 3. This step is now handled by import.php exclusively.
        // We ensure data is prepared for import in alto_properties, alto_branches.
        // The import.php script will then fetch full property details and map.

        Logger::log('Alto Data Synchronization completed. Data prepared for import.', 'INFO');
    }

    private function checkAndCreateTables()
    {
        // Table: ix3gf_alto_branches (Our internal tracking table for Alto Branch data)
        $createAltoBranchesTableSQL = "
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "alto_branches` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `alto_branch_id` VARCHAR(255) UNIQUE NOT NULL, -- Alto's unique ID for the branch, or 'FULL_BRANCH_LIST_XML'
                `xml_data` LONGTEXT NOT NULL,                    -- Stores the full XML response (either full <branches> or individual <branch>)
                `last_synced` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `processed` TINYINT(1) DEFAULT 0,                -- 0 = pending import, 1 = imported
                INDEX (`alto_branch_id`),
                INDEX (`processed`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        try {
            $this->db->exec($createAltoBranchesTableSQL);
            Logger::log("Table `" . DB_PREFIX . "alto_branches` checked/created successfully.", 'INFO');
        } catch (\PDOException $e) {
            Logger::log("Error creating table `" . DB_PREFIX . "alto_branches`: " . $e->getMessage(), 'ERROR');
        }

        // Table: ix3gf_alto_properties (Our internal tracking table for Alto Property data)
        $createAltoPropertiesTableSQL = "
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "alto_properties` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `alto_property_id` VARCHAR(255) UNIQUE NOT NULL, -- Alto's unique ID for the property
                `alto_branch_id` VARCHAR(255) NOT NULL,           -- The Alto branch ID this property belongs to
                `xml_data` LONGTEXT NOT NULL,                     -- Stores the full property summary XML response
                `last_synced` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `processed` TINYINT(1) DEFAULT 0,                 -- 0 = pending import, 1 = imported
                INDEX (`alto_property_id`),
                INDEX (`alto_branch_id`),
                INDEX (`processed`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        try {
            $this->db->exec($createAltoPropertiesTableSQL);
            Logger::log("Table `" . DB_PREFIX . "alto_properties` checked/created successfully.", 'INFO');
        } catch (\PDOException $e) {
            Logger::log("Error creating table `" . DB_PREFIX . "alto_properties`: " . $e->getMessage(), 'ERROR');
        }

        // Table: ix3gf_osrs_xml_details (OS Property's native table - we ensure it exists)
        $createOsrsXmlDetailsTableSQL = "
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "osrs_xml_details` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `xml_id` INT(11) DEFAULT NULL, -- This seems to be a foreign key to #__osrs_xml or property_id
                `obj_content` TEXT,             -- Where the XML data is stored
                `imported` TINYINT(1) UNSIGNED ZEROFILL DEFAULT NULL, -- Flag if it's imported by OS Property's internal means
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
        ";
        try {
            $this->db->exec($createOsrsXmlDetailsTableSQL);
            Logger::log("Table `" . DB_PREFIX . "osrs_xml_details` checked/created successfully.", 'INFO');
        } catch (\PDOException $e) {
            Logger::log("Error creating table `" . DB_PREFIX . "osrs_xml_details`: " . $e->getMessage(), 'ERROR');
        }


        // Ensure `alto_id` column in `ix3gf_osrs_properties` (for linking to Alto's property ID)
        $checkPropertyAltoIdColumnSQL = "
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '" . DB_PREFIX . "osrs_properties'
            AND COLUMN_NAME = 'alto_id';
        ";
        $stmt = $this->db->prepare($checkPropertyAltoIdColumnSQL);
        $stmt->execute();
        $columnExists = $stmt->fetchColumn();
        $stmt->closeCursor(); // Close cursor immediately
        unset($stmt); // Explicitly unset statement

        if ($columnExists == 0) {
            $addAltoIdColumnSQL = "
                ALTER TABLE `" . DB_PREFIX . "osrs_properties`
                ADD COLUMN `alto_id` VARCHAR(255) UNIQUE NULL AFTER `id`;
            ";
            try {
                $this->db->exec($addAltoIdColumnSQL);
                Logger::log("Column `alto_id` added to `ix3gf_osrs_properties`.", 'INFO');
            } catch (\PDOException $e) {
                Logger::log("Error adding column `alto_id` to `ix3gf_osrs_properties`: " . $e->getMessage(), 'ERROR');
            }
        } else {
            Logger::log("    Column `alto_id` already exists in `ix3gf_osrs_properties`.", 'INFO');
        }

        // Ensure `alto_branch_id` column in `ix3gf_osrs_companies` (for linking to Alto's branch ID)
        $checkCompanyBranchIdColumnSQL = "
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '" . DB_PREFIX . "osrs_companies'
            AND COLUMN_NAME = 'alto_branch_id';
        ";
        $stmt = $this->db->prepare($checkCompanyBranchIdColumnSQL);
        $stmt->execute();
        $columnExists = $stmt->fetchColumn();
        $stmt->closeCursor(); // Close cursor immediately
        unset($stmt); // Explicitly unset statement

        if ($columnExists == 0) {
            $addAltoBranchIdToCompanySQL = "
                ALTER TABLE `" . DB_PREFIX . "osrs_companies`
                ADD COLUMN `alto_branch_id` VARCHAR(255) UNIQUE NULL AFTER `id`;
            ";
            try {
                $this->db->exec($addAltoBranchIdToCompanySQL);
                Logger::log("Column `alto_branch_id` added to `ix3gf_osrs_companies`.", 'INFO');
            } catch (\PDOException $e) {
                Logger::log("Error adding column `alto_branch_id` to `ix3gf_osrs_companies`: " . $e->getMessage(), 'ERROR');
            }
        } else {
            Logger::log("    Column `alto_branch_id` already exists in `ix3gf_osrs_companies`.", 'INFO');
        }

        // Ensure `website` column in `ix3gf_osrs_companies`
        $checkCompanyWebsiteColumnSQL = "
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '" . DB_PREFIX . "osrs_companies'
            AND COLUMN_NAME = 'website';
        ";
        $stmt = $this->db->prepare($checkCompanyWebsiteColumnSQL);
        $stmt->execute();
        $columnExists = $stmt->fetchColumn();
        $stmt->closeCursor(); // Close cursor immediately
        unset($stmt); // Explicitly unset statement

        if ($columnExists == 0) {
            $addWebsiteToCompanySQL = "
                ALTER TABLE `" . DB_PREFIX . "osrs_companies`
                ADD COLUMN `website` VARCHAR(255) NULL AFTER `fax`;
            ";
            try {
                $this->db->exec($addWebsiteToCompanySQL);
                Logger::log("Column `website` added to `ix3gf_osrs_companies`.", 'INFO');
            } catch (\PDOException $e) {
                Logger::log("Error adding column `website` to `ix3gf_osrs_companies`: " . $e->getMessage(), 'ERROR');
            }
        } else {
            Logger::log("    Column `website` already exists in `ix3gf_osrs_companies`.", 'INFO');
        }

        // Ensure `alto_negotiator_id` column in `ix3gf_users` (for linking to Alto's negotiator ID)
        $checkUserAltoNegotiatorIdColumnSQL = "
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '" . DB_PREFIX . "users'
            AND COLUMN_NAME = 'alto_negotiator_id';
        ";
        $stmt = $this->db->prepare($checkUserAltoNegotiatorIdColumnSQL);
        $stmt->execute();
        $columnExists = $stmt->fetchColumn();
        $stmt->closeCursor(); // Close cursor immediately
        unset($stmt); // Explicitly unset statement

        if ($columnExists == 0) {
            $addAltoNegotiatorIdToUsersSQL = "
                ALTER TABLE `" . DB_PREFIX . "users`
                ADD COLUMN `alto_negotiator_id` VARCHAR(255) UNIQUE NULL;
            ";
            try {
                $this->db->exec($addAltoNegotiatorIdToUsersSQL);
                Logger::log("Column `alto_negotiator_id` added to `ix3gf_users`.", 'INFO');
            } catch (\PDOException $e) {
                Logger::log("Error adding column `alto_negotiator_id` to `ix3gf_users`: " . $e->getMessage(), 'ERROR');
            }
        } else {
            Logger::log("    Column `alto_negotiator_id` already exists in `ix3gf_users`.", 'INFO');
        }
    }

    private function processBranches($fullBranchesXmlResponse) // Renamed parameter for clarity
    {
        // Store the FULL XML response (`<branches>...</branches>`) in alto_branches
        // under a special ID so import.php can retrieve it and iterate through branches.
        $altoBranchIdForFullList = 'FULL_BRANCH_LIST_XML';

        // Check if the full branches XML in our tracking table (alto_branches) has changed
        $stmt = $this->db->prepare("SELECT xml_data FROM `" . DB_PREFIX . "alto_branches` WHERE alto_branch_id = ?");
        $stmt->execute([$altoBranchIdForFullList]);
        $existingFullBranchesXml = $stmt->fetchColumn();
        $stmt->closeCursor(); // Close cursor immediately after fetching
        unset($stmt); // Explicitly unset statement

        $fullBranchesXmlChanged = (hash('sha256', (string)$existingFullBranchesXml) !== hash('sha256', $fullBranchesXmlResponse));

        if ($fullBranchesXmlChanged || $existingFullBranchesXml === false) { // If new or changed
            Logger::log("        Full branches XML changed or new. Updating in " . DB_PREFIX . "alto_branches under ID '" . $altoBranchIdForFullList . "'.", 'INFO');

            $insertAltoBranchStmt = $this->db->prepare("
                INSERT INTO `" . DB_PREFIX . "alto_branches` (alto_branch_id, xml_data, last_synced, processed)
                VALUES (?, ?, NOW(), 0)
                ON DUPLICATE KEY UPDATE
                    xml_data = VALUES(xml_data),
                    last_synced = NOW(),
                    processed = 0; -- Mark as unprocessed for import
            ");
            try {
                if ($insertAltoBranchStmt->execute([$altoBranchIdForFullList, $fullBranchesXmlResponse])) {
                    Logger::log("        Full branches XML stored/updated in " . DB_PREFIX . "alto_branches. Marked for import.", 'INFO');
                } else {
                    Logger::log("        Failed to store/update full branches XML in " . DB_PREFIX . "alto_branches: " . json_encode($insertAltoBranchStmt->errorInfo()), 'ERROR');
                }
            } catch (\PDOException $e) {
                Logger::log("        PDO Error storing/updating full branches XML: " . $e->getMessage(), 'ERROR');
            }
            unset($insertAltoBranchStmt); // Explicitly unset for good measure
        } else {
            Logger::log("        Full branches XML unchanged. Skipping update in tracking table for ID '" . $altoBranchIdForFullList . "'.", 'INFO');
            // Still update `last_synced` to reflect that we checked it, but keep processed status.
            $updateSyncedStmt = $this->db->prepare("UPDATE `" . DB_PREFIX . "alto_branches` SET last_synced = NOW() WHERE alto_branch_id = ?");
            try {
                $updateSyncedStmt->execute([$altoBranchIdForFullList]);
            } catch (\PDOException $e) {
                Logger::log("        PDO Error updating last_synced for full branches XML: " . $e->getMessage(), 'ERROR');
            }
            unset($updateSyncedStmt); // Explicitly unset for good measure
        }
    }


    /**
     * Fetches property lists for all known branches and stores summaries.
     */
    private function processPropertyListingsForAllBranches()
    {
        // Select the full branch XML data from our internal alto_branches table.
        // We will parse this XML to get the individual branch URLs.
        $stmt = $this->db->prepare("SELECT alto_branch_id, xml_data FROM `" . DB_PREFIX . "alto_branches` WHERE alto_branch_id = 'FULL_BRANCH_LIST_XML'");
        $stmt->execute();
        $fullBranchListRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor(); // Close cursor immediately after fetching
        unset($stmt); // Explicitly unset statement

        if (empty($fullBranchListRow)) {
            Logger::log("    No full branch list XML found in `" . DB_PREFIX . "alto_branches`. Cannot fetch property lists.", 'INFO');
            return;
        }

        $fullBranchesXml = $fullBranchListRow['xml_data'];
        $simpleXmlBranches = \simplexml_load_string($fullBranchesXml);
        if ($simpleXmlBranches === false) {
            Logger::log("    Failed to parse full branches XML from DB for property fetching. XML content: " . substr($fullBranchesXml, 0, 500) . '...', 'ERROR');
            return;
        }

        foreach ($simpleXmlBranches->branch as $branchNode) {
            $altoBranchId = (string)$branchNode->branchid;
            // The alto_url for a branch is in the format: .../branch/{clientid}
            $branchApiUrl = (string)$branchNode->url;

            // IMPORTANT FIX: Use the URL from the branch XML, and append '/property' to it.
            // This URL already contains the correct internal branch identifier (e.g., 8191).
            $propertyListSpecificUrl = rtrim($branchApiUrl, '/') . '/property';

            Logger::log("    Fetching property list for branch ID: " . $altoBranchId . " from URL: " . $propertyListSpecificUrl, 'INFO');

            // Pass the DIRECT URL to AltoApi. We now treat this as a direct endpoint URL.
            // The AltoApi::callApi method has a case for filter_var($endpoint, FILTER_VALIDATE_URL) which will handle this.
            $propertyListXmlResponse = $this->altoApi->fetchPropertySummariesByUrl($propertyListSpecificUrl);

            if ($propertyListXmlResponse) {
                Logger::log("    Successfully retrieved property list for branch " . $altoBranchId . ".", 'INFO');
                $this->processPropertySummaries($propertyListXmlResponse, $altoBranchId); // Pass the full XML response
            } else {
                Logger::log("    Failed to retrieve property list for branch ID: " . $altoBranchId . ". Check API response and network. (This often returns 403 Forbidden until permissions are granted)", 'ERROR');
            }
        }
    }


    /**
     * Processes property summaries from a branch's property list XML.
     * Stores property summary data in `ix3gf_alto_properties`.
     * @param string $xmlResponse The full XML response string containing property summaries.
     * @param string $altoBranchId The Alto branch ID associated with these properties.
     */
    private function processPropertySummaries($xmlResponse, $altoBranchId)
    {
        $simpleXml = \simplexml_load_string($xmlResponse);
        if ($simpleXml === false) {
            Logger::log("    Failed to parse property summaries XML response. XML content: " . substr($xmlResponse, 0, 500) . '...', 'ERROR');
            return;
        }

        foreach ($simpleXml->property as $propertySummaryNode) {
            $altoPropertyId = (string)$propertySummaryNode->prop_id;
            // Ensure the XML string for storage always includes the XML declaration
            $currentPropertySummaryXml = '<?xml version="1.0" encoding="utf-8"?>' . $propertySummaryNode->asXML();

            if (\trim($altoPropertyId) === '') {
                Logger::log("        WARNING: Skipping property summary with empty Alto Property ID.", 'WARNING');
                continue;
            }

            Logger::log("    Processing property summary for Alto Property ID: " . $altoPropertyId . " (Branch: " . $altoBranchId . ")", 'INFO');

            try {
                // --- CRITICAL FIX FOR PDO EXCEPTION ON LINE ~395 ---
                // Ensure the SELECT query is fully consumed before proceeding with INSERT/UPDATE.
                $stmt = $this->db->prepare("SELECT xml_data FROM `" . DB_PREFIX . "alto_properties` WHERE alto_property_id = ? AND alto_branch_id = ?");
                $stmt->execute([$altoPropertyId, $altoBranchId]);
                $existingPropertySummaryXml = $stmt->fetchColumn(); // Fetches first column of first row (or false)
                $stmt->closeCursor(); // Explicitly close cursor here to prevent pending results
                unset($stmt); // Explicitly unset statement for maximum resource release
                // --- END CRITICAL FIX ---


                $propertyXmlChanged = (hash('sha256', (string)$existingPropertySummaryXml) !== hash('sha256', $currentPropertySummaryXml));

                if ($propertyXmlChanged || $existingPropertySummaryXml === false) { // If new or changed
                    Logger::log("        Property " . ($altoPropertyId ?: 'N/A') . " summary XML changed or new. Updating in " . DB_PREFIX . "alto_properties.", 'INFO');

                    $insertAltoPropertyStmt = $this->db->prepare("
                        INSERT INTO `" . DB_PREFIX . "alto_properties` (alto_property_id, alto_branch_id, xml_data, last_synced, processed)
                        VALUES (?, ?, ?, NOW(), 0)
                        ON DUPLICATE KEY UPDATE
                            alto_branch_id = VALUES(alto_branch_id),
                            xml_data = VALUES(xml_data),
                            last_synced = NOW(),
                            processed = 0; -- Mark as unprocessed for import
                    ");
                    // This execute was line ~395 and throwing the error. The fix is above and `unset($stmt)`.
                    if ($insertAltoPropertyStmt->execute([$altoPropertyId, $altoBranchId, $currentPropertySummaryXml])) {
                        Logger::log("        Property " . ($altoPropertyId ?: 'N/A') . " summary XML stored/updated in " . DB_PREFIX . "alto_properties. Marked for import.", 'INFO');
                    } else {
                        Logger::log("        Failed to store/update property " . ($altoPropertyId ?: 'N/A') . " summary XML in " . DB_PREFIX . "alto_properties: " . json_encode($insertAltoPropertyStmt->errorInfo()), 'ERROR');
                    }
                    unset($insertAltoPropertyStmt); // Explicitly unset for good measure
                } else {
                    Logger::log("        Property " . ($altoPropertyId ?: 'N/A') . " summary XML unchanged. Skipping update in tracking table.", 'INFO');

                    // Backfill: if this property has no images yet, still queue it for import
                    if (OsPropertyMapper::propertyNeedsImages($altoPropertyId)) {
                        Logger::log("        Property {$altoPropertyId} has no images yet — queuing for import anyway.", 'INFO');

                        $stmtQueue = $this->db->prepare("
                            INSERT INTO `" . DB_PREFIX . "alto_properties` (alto_property_id, alto_branch_id, xml_data, last_synced, processed)
                            VALUES (?, ?, ?, NOW(), 0)
                            ON DUPLICATE KEY UPDATE
                                last_synced = NOW(),
                                processed   = 0
                        ");
                        $stmtQueue->execute([$altoPropertyId, $altoBranchId, $currentPropertySummaryXml]);
                        unset($stmtQueue);
                    } else {
                        // Just refresh last_synced so we know we checked it
                        $updateSyncedStmt = $this->db->prepare("UPDATE `" . DB_PREFIX . "alto_properties` SET last_synced = NOW() WHERE alto_property_id = ? AND alto_branch_id = ?");
                        $updateSyncedStmt->execute([$altoPropertyId, $altoBranchId]);
                        unset($updateSyncedStmt);
                    }
                }
            } catch (\PDOException $e) {
                Logger::log('        CRITICAL ERROR storing property summary ' . $altoPropertyId . ': ' . $e->getMessage(), 'CRITICAL');
                // Do NOT mark as processed, let it retry on next run.
            } catch (\Exception $e) {
                Logger::log('        GENERAL ERROR storing property summary ' . $altoPropertyId . ': ' . $e->getMessage(), 'CRITICAL');
                // Do NOT mark as processed, let it retry on next run.
            }
        }
    }
}

// Initialize Logger before any other classes to ensure it's available for all logging calls
Logger::init(LOGS_DIR . 'alto-sync.log');

// Run the synchronization
$synchronizer = new AltoDataSynchronizer();
$synchronizer->runSynchronization();
