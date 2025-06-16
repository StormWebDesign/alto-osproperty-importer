<?php
// sync.php - Main script for Alto data synchronization

// Include the configuration file for database credentials and other settings
require_once __DIR__ . '/config.php';

// Set up error logging
ini_set('log_errors', 1);
ini_set('error_log', LOGS_DIR . 'alto-sync.log'); // Use LOGS_DIR from config.php
error_reporting(E_ALL);

// Autoload classes (assuming standard PSR-4 structure for AltoSync namespace)
spl_autoload_register(function ($class) {
    $prefix = 'AltoSync\\';
    $base_dir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    // Replace namespace separators with directory separators and append .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Assuming CurlHelper is still in AltoSync/Utils/CurlHelper.php
use AltoSync\Utils\CurlHelper;
// Assuming OsPropertyMapper is in AltoSync/Mapper/OsPropertyMapper.php
use AltoSync\Mapper\OsPropertyMapper;

/**
 * Syncs Alto data with the database.
 */
class AltoDataSynchronizer
{

    private $db; // PDO connection
    private $curlHelper;
    private $mapper;
    private $datafeedId;
    private $apiVersion = 'v13';

    // Removed altoUsername and altoPassword as they are no longer used directly for token acquisition here
    private $oauthToken = null; // Will store the Bearer token loaded from file

    public function __construct()
    {
        // Removed direct usage of ALTO_API_USERNAME and ALTO_API_PASSWORD here
        $this->datafeedId = ALTO_API_DATAFEED_ID;

        // Direct PDO connection using constants from config.php
        try {
            $this->db = new \PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed.");
        }

        $this->curlHelper = new CurlHelper();
        $this->mapper = new OsPropertyMapper();

        // Attempt to load token from file at initialization
        $this->loadToken();
    }

    public function runSynchronization()
    {
        error_log("------------------------------------------------------------------------");
        error_log("Alto Data Synchronization started: " . date('Y-m-d H:i:s T'));
        error_log("------------------------------------------------------------------------");

        error_log("\nStarting Alto Data Synchronization...");

        // 1. Check for required tables
        error_log("    Checking for required tables...");
        $this->checkAndCreateTables();

        // Ensure we have a valid token before proceeding with API calls
        if (!$this->oauthToken) {
            error_log("    No valid OAuth token found. Ensure get_token.php has been run and tokens.txt exists with a valid token.");
            error_log("    Aborting synchronization.");
            return; // Stop if we can't get a token
        } else {
            error_log("    Using existing OAuth token from tokens.txt.");
        }


        // 1. Fetching branches list
        error_log("1. Fetching branches list...");
        $branchApiUrl = ALTO_API_BASE_URL . $this->datafeedId . '/' . $this->apiVersion . '/branch';
        // Pass a log prefix for better debugging
        $branchResponse = $this->makeAuthenticatedApiCall($branchApiUrl, 'GET', [], [], 'Branch List - ');

        if ($branchResponse['success']) {
            $branchXml = $branchResponse['data'];
            error_log("    API call to branch successful.");
            $this->processBranches($branchXml);
        } else {
            error_log("    Failed to retrieve branches XML. HTTP Code: " . $branchResponse['http_code'] . ", Response: " . $branchResponse['data']);
        }

        // 2. Fetching property summaries for each branch
        error_log("2. Fetching property summaries for each branch...");
        $this->processPropertyListingsForAllBranches();


        // 3. Processing properties marked for import (if any were not processed immediately)
        error_log("3. Processing properties marked for import...");
        $this->processPendingProperties();

        error_log("Alto Data Synchronization completed.");
    }

    private function checkAndCreateTables()
    {
        // Table: ix3gf_alto_branches
        $createBranchesTableSQL = "
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "alto_branches` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `firmid` VARCHAR(255) UNIQUE NOT NULL,
                `branchid` VARCHAR(255) NOT NULL,
                `branch_name` VARCHAR(255),
                `alto_url` VARCHAR(255) UNIQUE, -- Store the Get Branch URL here
                `last_synced` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (`firmid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        try {
            $stmt = $this->db->prepare($createBranchesTableSQL);
            $stmt->execute();
            error_log("Table `" . DB_PREFIX . "alto_branches` checked/created successfully.");
        } catch (\PDOException $e) {
            error_log("Error creating table `" . DB_PREFIX . "alto_branches`: " . $e->getMessage());
        }

        // Table: ix3gf_osrs_xml_details
        $createXmlDetailsTableSQL = "
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "osrs_xml_details` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `alto_id` VARCHAR(255) UNIQUE NOT NULL,
                `entity_type` VARCHAR(50) NOT NULL, -- e.g., 'branch', 'property'
                `xml_hash` VARCHAR(64) NOT NULL, -- SHA256 hash of XML data
                `xml_data` LONGTEXT NOT NULL,
                `last_modified` DATETIME,
                `imported` TINYINT(1) DEFAULT 0, -- 0 = pending, 1 = imported
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (`entity_type`),
                INDEX (`imported`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        try {
            $stmt = $this->db->prepare($createXmlDetailsTableSQL);
            $stmt->execute();
            error_log("Table `" . DB_PREFIX . "osrs_xml_details` checked/created successfully.");
        } catch (\PDOException $e) {
            error_log("Error creating table `" . DB_PREFIX . "osrs_xml_details`: " . $e->getMessage());
        }

        // Table: ix3gf_alto_properties (NEWLY ADDED)
        $createAltoPropertiesTableSQL = "
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "alto_properties` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `alto_id` VARCHAR(255) UNIQUE NOT NULL,
                `branch_alto_id` VARCHAR(255) NOT NULL,
                `last_synced` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (`alto_id`),
                INDEX (`branch_alto_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        try {
            $stmt = $this->db->prepare($createAltoPropertiesTableSQL);
            $stmt->execute();
            error_log("Table `" . DB_PREFIX . "alto_properties` checked/created successfully.");
        } catch (\PDOException $e) {
            error_log("Error creating table `" . DB_PREFIX . "alto_properties`: " . $e->getMessage());
        }


        // Check for `alto_id` column in `ix3gf_osrs_properties`
        $checkPropertyColumnSQL = "
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '" . DB_PREFIX . "osrs_properties'
            AND COLUMN_NAME = 'alto_id';
        ";
        $stmt = $this->db->prepare($checkPropertyColumnSQL);
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $addAltoIdColumnSQL = "
                ALTER TABLE `" . DB_PREFIX . "osrs_properties`
                ADD COLUMN `alto_id` VARCHAR(255) UNIQUE NULL AFTER `id`;
            ";
            try {
                $stmt = $this->db->prepare($addAltoIdColumnSQL);
                $stmt->execute();
                error_log("Column `alto_id` added to `ix3gf_osrs_properties`.");
            } catch (\PDOException $e) {
                error_log("Error adding column `alto_id` to `ix3gf_osrs_properties`: " . $e->getMessage());
            }
        } else {
            error_log("    Column `alto_id` already exists in `ix3gf_osrs_properties`.");
        }
    }


    /**
     * Loads the OAuth token from the tokens.txt file.
     */
    private function loadToken()
    {
        if (file_exists(TOKENS_FILE)) {
            $token = trim(file_get_contents(TOKENS_FILE));
            if (!empty($token)) {
                $this->oauthToken = $token;
                error_log("    Loaded OAuth token from " . TOKENS_FILE . ".");
            } else {
                error_log("    " . TOKENS_FILE . " is empty. Token not loaded.");
                $this->oauthToken = null;
            }
        } else {
            error_log("    " . TOKENS_FILE . " not found. Token not loaded.");
            $this->oauthToken = null;
        }
    }

    // Removed acquireNewToken() - replaced by get_token.php
    // Removed storeToken() - replaced by get_token.php
    // Removed getTokenFromResponse() - token is now read directly from file

    /**
     * Makes an API call with authentication.
     * @param string $url The API endpoint URL.
     * @param string $method The HTTP method (e.g., 'GET', 'POST').
     * @param array $data The data for POST requests.
     * @param array $headers Additional headers to send.
     * @param string $log_prefix A prefix for logging purposes.
     * @return array Containing 'success' (bool), 'data' (string XML or error msg), 'http_code', 'headers'.
     */
    private function makeAuthenticatedApiCall($url, $method = 'GET', $data = [], $headers = [], $log_prefix = '')
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // To capture response headers for logging
        curl_setopt($ch, CURLOPT_ENCODING, ""); // Handle various encodings
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        // Base64 encode the token before adding to the Authorization header
        $encodedToken = base64_encode($this->oauthToken);

        // Set the Authorization header correctly, using the raw token directly
        $curl_headers = [
            'Host: webservices.vebra.com',
            'Authorization: Basic ' . $encodedToken, // Use the BASE64 ENCODED token here
            'Accept: application/xml'
        ];

        // Merge any additional headers passed to the function
        if (!empty($headers)) {
            $curl_headers = array_merge($curl_headers, $headers);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);

        // You might also want to enable verbose debug for cURL
        // For debugging specific API calls within sync.php, uncomment these lines temporarily:
        // curl_setopt($ch, CURLOPT_VERBOSE, true);
        // $verbose_log_file = fopen(LOGS_DIR . 'curl_verbose_sync_debug.log', 'w+'); // Make sure LOGS_DIR is defined and writable
        // curl_setopt($ch, CURLOPT_STDERR, $verbose_log_file);


        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        $curl_error = curl_error($ch);
        curl_close($ch);

        // Close verbose log file if it was opened
        // if (isset($verbose_log_file) && is_resource($verbose_log_file)) {
        //     fclose($verbose_log_file);
        // }

        // Log response details
        error_log($log_prefix . "URL: " . $url);
        error_log($log_prefix . "HTTP Code: " . $http_code);
        error_log($log_prefix . "Curl Error: " . ($curl_error ? $curl_error : 'None'));
        error_log($log_prefix . "Response Headers:\n" . $header);
        error_log($log_prefix . "Response Body:\n" . $body);


        // Determine success based on HTTP code (2xx series)
        $success = ($http_code >= 200 && $http_code < 300);

        return [
            'success' => $success,
            'data' => $body, // This will be the XML or error message
            'http_code' => $http_code,
            'headers' => $header // Useful for further debugging
        ];
    }

    private function processBranches($xml)
    {
        $simpleXml = \simplexml_load_string($xml);
        if ($simpleXml === false) {
            error_log("    Failed to parse branch XML. XML content: " . substr($xml, 0, 500) . '...'); // Log partial XML for debugging
            return;
        }

        foreach ($simpleXml->branch as $branchNode) {
            $firmId = (string)$branchNode->firmid;
            $branchId = (string)$branchNode->branchid;
            $branchName = (string)$branchNode->name;
            $altoUrl = (string)$branchNode->url; // This is the URL to Get Branch details

            error_log("    Processing branch summary ID: " . ($branchId ? $branchId : 'N/A') . " (Firm: " . ($firmId ? $firmId : 'N/A') . ")");

            // Check if XML data for this branch has changed
            $currentXmlHash = hash('sha256', $branchNode->asXML());
            $stmt = $this->db->prepare("SELECT xml_hash, imported FROM `" . DB_PREFIX . "osrs_xml_details` WHERE alto_id = ? AND entity_type = 'branch'");
            $stmt->execute([$branchId]);
            $xmlDetails = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($xmlDetails && $xmlDetails['xml_hash'] === $currentXmlHash) {
                error_log("        Branch " . ($branchId ? $branchId : 'N/A') . " XML unchanged. Skipping storage.");
                // If XML is unchanged, but not yet imported, mark for import
                if (isset($xmlDetails['imported']) && $xmlDetails['imported'] == 0) {
                    // Still call mapBranchDetailsToDatabase to ensure it's processed and marked imported
                    $this->mapper->mapBranchDetailsToDatabase($branchNode->asXML());
                    // Update record in osrs_xml_details table to mark as imported
                    $updateStmt = $this->db->prepare("UPDATE `" . DB_PREFIX . "osrs_xml_details` SET imported = 1, updated_at = NOW() WHERE alto_id = ? AND entity_type = 'branch'");
                    $updateStmt->execute([$branchId]);
                    error_log("        Branch " . ($branchId ? $branchId : 'N/A') . " re-mapped and marked as imported.");
                }
                // Also ensure alto_branches table is up to date, even if xml_details unchanged.
                // This will update 'last_synced' and 'alto_url' in alto_branches.
                $insertAltoBranchStmt = $this->db->prepare("
                    INSERT INTO `" . DB_PREFIX . "alto_branches` (firmid, branchid, branch_name, alto_url, last_synced)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name), alto_url = VALUES(alto_url), last_synced = NOW()
                ");
                $insertAltoBranchStmt->execute([$firmId, $branchId, $branchName, $altoUrl]);
                continue; // No new XML to store or process unless it was pending.
            }

            // Store or update in osrs_xml_details (new or changed branch XML)
            $stmt = $this->db->prepare("
                INSERT INTO `" . DB_PREFIX . "osrs_xml_details` (alto_id, entity_type, xml_hash, xml_data, last_modified, imported)
                VALUES (?, 'branch', ?, ?, NOW(), 0)
                ON DUPLICATE KEY UPDATE xml_hash = VALUES(xml_hash), xml_data = VALUES(xml_data), last_modified = VALUES(last_modified), imported = 0, updated_at = NOW()
            ");
            if ($stmt->execute([$branchId, $currentXmlHash, $branchNode->asXML()])) {
                error_log("        Branch " . ($branchId ? $branchId : 'N/A') . " XML inserted into " . DB_PREFIX . "osrs_xml_details. Marking for import.");
            } else {
                error_log("        Failed to insert/update branch " . ($branchId ? $branchId : 'N/A') . " XML in " . DB_PREFIX . "osrs_xml_details: " . json_encode($stmt->errorInfo()));
            }

            // Also insert/update into `ix3gf_alto_branches` which tracks branch details and their "Get Branch" URLs
            $insertAltoBranchStmt = $this->db->prepare("
                INSERT INTO `" . DB_PREFIX . "alto_branches` (firmid, branchid, branch_name, alto_url, last_synced)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name), alto_url = VALUES(alto_url), last_synced = NOW()
            ");
            if ($insertAltoBranchStmt->execute([$firmId, $branchId, $branchName, $altoUrl])) {
                error_log("        Branch " . ($branchId ? $branchId : 'N/A') . " updated in " . DB_PREFIX . "alto_branches.");
            } else {
                error_log("        Failed to insert/update branch " . ($branchId ? $branchId : 'N/A') . " in " . DB_PREFIX . "alto_branches: " . json_encode($insertAltoBranchStmt->errorInfo()));
            }


            // Map branch details to database (if it's new or changed)
            $this->mapper->mapBranchDetailsToDatabase($branchNode->asXML());

            // After successful mapping, mark as imported in osrs_xml_details
            $updateStmt = $this->db->prepare("UPDATE `" . DB_PREFIX . "osrs_xml_details` SET imported = 1, updated_at = NOW() WHERE alto_id = ? AND entity_type = 'branch'");
            if ($updateStmt->execute([$branchId])) {
                error_log("        Branch " . ($branchId ? $branchId : 'N/A') . " re-mapped and marked as imported.");
            } else {
                error_log("        Failed to mark branch " . ($branchId ? $branchId : 'N/A') . " as imported: " . json_encode($updateStmt->errorInfo()));
            }
        }
    }


    /**
     * Fetches property lists for all known branches and stores summaries.
     */
    private function processPropertyListingsForAllBranches()
    {
        $stmt = $this->db->prepare("SELECT branchid, alto_url FROM `" . DB_PREFIX . "alto_branches`");
        $stmt->execute();
        $branches = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($branches)) {
            error_log("    No branches found in `" . DB_PREFIX . "alto_branches` to fetch property lists for.");
            return;
        }

        foreach ($branches as $branch) {
            $branchId = $branch['branchid'];
            // The alto_url for a branch is in the format: .../branch/{clientid}
            // We need to append /property to get the list of properties for that branch.
            $propertyListUrl = rtrim($branch['alto_url'], '/') . '/property';

            error_log("    Fetching property list for branch ID: " . $branchId . " from URL: " . $propertyListUrl);
            // Pass a log prefix for better debugging
            $propertyListResponse = $this->makeAuthenticatedApiCall($propertyListUrl, 'GET', [], [], 'Property List - ');

            if ($propertyListResponse['success']) {
                $propertyListXml = $propertyListResponse['data'];
                error_log("    Successfully retrieved property list for branch " . $branchId . ".");
                $this->processPropertySummaries($propertyListXml, $branchId); // Pass branchId to processPropertySummaries
            } else {
                error_log("    Failed to retrieve property list for branch ID: " . $branchId . ". HTTP Code: " . $propertyListResponse['http_code'] . ", Response: " . $propertyListResponse['data']);
            }
        }
    }


    /**
     * Processes property summaries from a branch's property list XML.
     * @param string $xml The XML string containing property summaries.
     * @param string $branchId The branch ID associated with these properties.
     */
    private function processPropertySummaries($xml, $branchId)
    {
        $simpleXml = \simplexml_load_string($xml);
        if ($simpleXml === false) {
            error_log("    Failed to parse property summaries XML. XML content: " . substr($xml, 0, 500) . '...'); // Log partial XML for debugging
            return;
        }

        foreach ($simpleXml->property as $propertySummaryNode) {
            $altoId = (string)$propertySummaryNode->prop_id; // Corrected to prop_id from documentation
            $lastChangedDate = (string)$propertySummaryNode->lastchanged; // Assuming lastchanged exists
            $fullPropertyUrl = (string)$propertySummaryNode->url; // URL to get full property details

            error_log("    Processing property summary ID: " . ($altoId ? $altoId : 'N/A') . " for branch " . $branchId);

            // Check if XML data for this property has changed
            $currentXmlHash = hash('sha256', $propertySummaryNode->asXML());
            $stmt = $this->db->prepare("SELECT xml_hash, imported FROM `" . DB_PREFIX . "osrs_xml_details` WHERE alto_id = ? AND entity_type = 'property'");
            $stmt->execute([$altoId]);
            $xmlDetails = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Log if a property summary has a new or changed status
            if ($xmlDetails) {
                if ($xmlDetails['xml_hash'] === $currentXmlHash) {
                    error_log("        Property " . ($altoId ? $altoId : 'N/A') . " XML unchanged.");
                } else {
                    error_log("        Property " . ($altoId ? $altoId : 'N/A') . " XML changed. Marking for re-import.");
                    // Update in osrs_xml_details
                    $updateStmt = $this->db->prepare("
                        UPDATE `" . DB_PREFIX . "osrs_xml_details`
                        SET xml_hash = ?, xml_data = ?, last_modified = ?, imported = 0, updated_at = NOW()
                        WHERE alto_id = ? AND entity_type = 'property'
                    ");
                    $updateStmt->execute([$currentXmlHash, $propertySummaryNode->asXML(), $lastChangedDate, $altoId]);
                }
                // If property is marked as imported but data has changed (above), it's now 0.
                // If it was already 0, keep it at 0.
                // Otherwise, it was already processed, and no changes, so skip.
                if (isset($xmlDetails['imported']) && $xmlDetails['imported'] == 1 && $xmlDetails['xml_hash'] === $currentXmlHash) {
                    continue; // Skip if already imported and no changes
                }
            } else {
                error_log("        New Property " . ($altoId ? $altoId : 'N/A') . ". Storing for import.");
                // Insert into osrs_xml_details
                $insertStmt = $this->db->prepare("
                    INSERT INTO `" . DB_PREFIX . "osrs_xml_details` (alto_id, entity_type, xml_hash, xml_data, last_modified, imported)
                    VALUES (?, 'property', ?, ?, ?, 0)
                ");
                $insertStmt->execute([$altoId, $currentXmlHash, $propertySummaryNode->asXML(), $lastChangedDate]);
            }

            // At this point, the property is either new or updated, and marked as `imported = 0` (pending)
            // The full property XML will be fetched and processed later in processPendingProperties()
            // We need to associate the branchId with the property for later mapping.
            // This can be done by storing the branchId in the xml_data or a separate column,
            // or by ensuring processPendingProperties can look up the branchId.
            // For now, let's update the existing entry (or create if new) in ix3gf_alto_properties
            // to store the branchId. This is an internal tracking table.
            $stmt = $this->db->prepare("
                INSERT INTO `" . DB_PREFIX . "alto_properties` (alto_id, branch_alto_id, last_synced)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE branch_alto_id = VALUES(branch_alto_id), last_synced = NOW()
            ");
            try {
                $stmt->execute([$altoId, $branchId]);
                error_log("        Property " . $altoId . " associated with branch " . $branchId . " in alto_properties table.");
            } catch (\PDOException $e) {
                error_log("        Failed to associate property " . $altoId . " with branch " . $branchId . " in alto_properties: " . $e->getMessage());
            }
        }
    }

    private function processPendingProperties()
    {
        $pendingPropertiesStmt = $this->db->prepare("
            SELECT oxd.alto_id, oxd.xml_data, ap.branch_alto_id
            FROM `" . DB_PREFIX . "osrs_xml_details` oxd
            JOIN `" . DB_PREFIX . "alto_properties` ap ON oxd.alto_id = ap.alto_id
            WHERE oxd.entity_type = 'property' AND oxd.imported = 0
            LIMIT 50 -- Process in batches to avoid memory issues
        ");
        $pendingPropertiesStmt->execute();
        $pendingProperties = $pendingPropertiesStmt->fetchAll(\PDO::FETCH_ASSOC);

        $processedCount = 0;
        foreach ($pendingProperties as $propertyRow) {
            $altoId = $propertyRow['alto_id'];
            $propertySummaryXml = $propertyRow['xml_data']; // This is the summary XML
            $branchAltoId = $propertyRow['branch_alto_id']; // Get the branch ID from alto_properties

            // From the summary XML, extract the URL to get the full property details
            $simpleXml = \simplexml_load_string($propertySummaryXml);
            if ($simpleXml === false) {
                error_log("    Failed to parse summary XML for pending property " . $altoId . ". Skipping. XML content: " . substr($propertySummaryXml, 0, 500) . '...');
                // Mark as imported to avoid re-processing this problematic entry repeatedly
                $this->markXmlDetailsAsImported($altoId, 'property');
                continue;
            }
            $fullPropertyUrl = (string)$simpleXml->url; // This URL is the 'Get Property' endpoint

            if (!$fullPropertyUrl) {
                error_log("    No full property URL found in summary for Alto ID: " . $altoId . ". Skipping.");
                // Mark as imported to avoid re-processing this problematic entry repeatedly
                $this->markXmlDetailsAsImported($altoId, 'property');
                continue;
            }

            error_log("    Fetching full details for pending property ID: " . $altoId . " from URL: " . $fullPropertyUrl);
            // Pass a log prefix for better debugging
            $fullPropertyResponse = $this->makeAuthenticatedApiCall($fullPropertyUrl, 'GET', [], [], 'Full Property - ');

            if ($fullPropertyResponse['success']) {
                $fullPropertyXml = $fullPropertyResponse['data'];
                error_log("        Mapping full property " . $altoId . " to database.");
                // Pass branchAltoId to the mapper
                $this->mapper->mapPropertyDetailsToDatabase($fullPropertyXml, $branchAltoId);
                $this->markXmlDetailsAsImported($altoId, 'property');
                $processedCount++;
            } else {
                error_log("    Failed to fetch full property details for Alto ID: " . $altoId . ". HTTP Code: " . $fullPropertyResponse['http_code'] . ", Response: " . $fullPropertyResponse['data']);
                // Do NOT mark as imported here, so it can be retried
            }
        }
        error_log("    Finished re-processing " . $processedCount . " properties.");
    }

    private function markXmlDetailsAsImported($altoId, $entityType)
    {
        $stmt = $this->db->prepare("UPDATE `" . DB_PREFIX . "osrs_xml_details` SET imported = 1, updated_at = NOW() WHERE alto_id = ? AND entity_type = ?");
        $stmt->execute([$altoId, $entityType]);
    }
}

// Run the synchronization
$synchronizer = new AltoDataSynchronizer();
$synchronizer->runSynchronization();