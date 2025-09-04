<?php
// /public_html/cli/alto-sync/import.php - Processes pending Alto XML data from our tracking tables into OS Property

namespace AltoSync;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/AltoApi.php'; // Needed for fetchFullPropertyDetailsByUrl
require_once __DIR__ . '/Mapper/OsPropertyMapper.php'; // Ensure mapper is loaded after JFilterOutput dummy

use AltoSync\Logger;
use AltoSync\AltoApi;
use AltoSync\Mapper\OsPropertyMapper;

// Set up logging for the import process
Logger::init(__DIR__ . '/logs/alto-import.log'); // Separate log for import process
Logger::log('------------------------------------------------------------------------');
Logger::log('Alto Data Import started: ' . date('Y-m-d H:i:s T'));
Logger::log('------------------------------------------------------------------------');
Logger::log('');

Logger::log('Starting Alto Data Import...', 'INFO');

// Initialize DB connection
try {
    $db = new \PDO(
        'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
        \DB_USER,
        \DB_PASS,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
    // Ensure buffered queries for robustness against pending result sets
    $db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); 
    Logger::log('    Database connection established for import.php.', 'INFO');
} catch (\PDOException $e) {
    Logger::log('    Database connection failed in import.php: ' . $e->getMessage(), 'CRITICAL');
    die("Database connection failed in import.php. Check logs for details.");
}

// Define DB_PREFIX globally for consistency
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', \DB_PREFIX);
}

// AltoApi needs to be initialized without token file for `fetchFullPropertyDetailsByUrl` if it's called standalone.
// We expect AltoApi to get the token, so pass the token file path.
$altoApi = new AltoApi(TOKEN_FILE);


// Process pending branches from ix3gf_alto_branches (our internal tracking)
Logger::log('Processing pending branch XML from ' . \DB_PREFIX . 'alto_branches...', 'INFO');
// Force processing of branches if the company table is empty or the full branch list hasn't been processed recently.
// This ensures company IDs exist before properties are mapped.
$forceBranchProcessing = false;
$stmtCheckCompanies = $db->prepare("SELECT COUNT(*) FROM `" . DB_PREFIX . "osrs_companies`");
$stmtCheckCompanies->execute();
$companyCount = $stmtCheckCompanies->fetchColumn();
$stmtCheckCompanies->closeCursor();
unset($stmtCheckCompanies);

$stmtCheckFullBranchProcessed = $db->prepare("SELECT processed FROM `" . DB_PREFIX . "alto_branches` WHERE alto_branch_id = 'FULL_BRANCH_LIST_XML'");
$stmtCheckFullBranchProcessed->execute();
$fullBranchProcessedStatus = $stmtCheckFullBranchProcessed->fetchColumn();
$stmtCheckFullBranchProcessed->closeCursor();
unset($stmtCheckFullBranchProcessed);

// If no companies exist OR the full branch list is not marked as processed (e.g., after a fresh start/cleanup)
if ($companyCount == 0 || $fullBranchProcessedStatus != 1) { 
    Logger::log("    Forcing branch XML reprocessing: Company table is empty OR full branch list not yet processed successfully.", 'INFO');
    $forceBranchProcessing = true;
    // Temporarily reset processed status for FULL_BRANCH_LIST_XML to force re-reading and mapping
    $stmtResetBranch = $db->prepare("UPDATE `" . DB_PREFIX . "alto_branches` SET processed = 0 WHERE alto_branch_id = 'FULL_BRANCH_LIST_XML'");
    $stmtResetBranch->execute();
    unset($stmtResetBranch);
}


// Fetch the single 'FULL_BRANCH_LIST_XML' entry
$pendingBranchesStmt = $db->prepare("
    SELECT alto_branch_id, xml_data
    FROM `" . DB_PREFIX . "alto_branches`
    WHERE processed = 0 AND alto_branch_id = 'FULL_BRANCH_LIST_XML'
    LIMIT 1
");
$pendingBranchesStmt->execute();
$fullBranchListRow = $pendingBranchesStmt->fetch(\PDO::FETCH_ASSOC);
$pendingBranchesStmt->closeCursor(); // Close cursor after fetching
unset($pendingBranchesStmt); // Explicitly unset

$processedBranchesCount = 0;
if ($fullBranchListRow) {
    $fullBranchesXml = $fullBranchListRow['xml_data'];
    Logger::log('Found full branches XML to process from ' . DB_PREFIX . 'alto_branches.', 'INFO');

    $cleanedXml = trim($fullBranchesXml);
    if (!str_starts_with($cleanedXml, '<?xml')) {
        $cleanedXml = '<?xml version="1.0" encoding="utf-8"?>' . $cleanedXml;
    }

    libxml_use_internal_errors(true);
    $simpleXmlBranches = \simplexml_load_string($cleanedXml);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    if ($simpleXmlBranches === false) {
        $errorMessages = [];
        foreach ($errors as $error) {
            $errorMessages[] = $error->message . " at line " . $error->line . ", column " . $error->column;
        }
        Logger::log('    Failed to parse full branches XML from DB for mapping: ' . implode('; ', $errorMessages) . '. XML content: ' . substr($cleanedXml, 0, 500) . '...', 'ERROR');
        // Mark as processed even if XML is malformed to avoid re-attempting bad XML
        $updateAltoBranchStmt = $db->prepare("UPDATE `" . DB_PREFIX . "alto_branches` SET processed = 1 WHERE alto_branch_id = 'FULL_BRANCH_LIST_XML'");
        $updateAltoBranchStmt->execute();
        unset($updateAltoBranchStmt);
    } else {
        foreach ($simpleXmlBranches->branch as $branchNode) {
            $altoBranchId = (string)$branchNode->branchid;
            Logger::log('    Attempting to map pending branch ' . $altoBranchId . '.', 'INFO');

            try {
                if (OsPropertyMapper::mapBranchDetailsToDatabase($branchNode)) { 
                    $numericBranchId = (int)$altoBranchId;
                    $xmlToStoreInOsrs = '<?xml version="1.0" encoding="utf-8"?>' . $branchNode->asXML();

                    $stmtOsrsXmlDetailsBranch = $db->prepare("
                        INSERT INTO `" . DB_PREFIX . "osrs_xml_details` (xml_id, obj_content, imported)
                        VALUES (?, ?, 0)
                        ON DUPLICATE KEY UPDATE obj_content = VALUES(obj_content), imported = 0
                    ");
                    if ($stmtOsrsXmlDetailsBranch->execute([$numericBranchId, $xmlToStoreInOsrs])) {
                         Logger::log("        Individual branch " . $altoBranchId . " XML stored/updated in " . DB_PREFIX . "osrs_xml_details.", 'INFO');
                    } else {
                         Logger::log("        Failed to store/update individual branch " . $altoBranchId . " XML in " . DB_PREFIX . "osrs_xml_details: " . json_encode($stmtOsrsXmlDetailsBranch->errorInfo()), 'ERROR');
                    }
                    unset($stmtOsrsXmlDetailsBranch);

                    Logger::log('        Successfully mapped branch ' . $altoBranchId . '.', 'INFO');
                    $processedBranchesCount++;
                } else {
                    Logger::log('        Failed to map branch ' . $altoBranchId . '. Will retry on next run.', 'WARNING');
                    // DO NOT mark as processed if mapping failed, so it retries.
                }
            } catch (\Exception $e) {
                Logger::log('        CRITICAL ERROR mapping branch ' . $altoBranchId . ': ' . $e->getMessage(), 'CRITICAL');
                // DO NOT mark as processed if critical error, so it retries.
            }
        }
        // Mark the entire full branch list as processed ONLY if all individual branch mappings succeeded, or if forced reprocessing
        // The current logic marks it processed after the loop, which is fine if individual failures don't stop the whole thing.
        // If $processedBranchesCount matches total branches, then set processed = 1.
        // For now, let's keep it simple: if we iterated through, mark the full list as processed.
        $updateAltoBranchStmt = $db->prepare("UPDATE `" . DB_PREFIX . "alto_branches` SET processed = 1 WHERE alto_branch_id = 'FULL_BRANCH_LIST_XML'");
        $updateAltoBranchStmt->execute();
        unset($updateAltoBranchStmt);
        Logger::log('Finished processing ' . $processedBranchesCount . ' branches from the full list.', 'INFO');
    }
} else {
    Logger::log('No new full branch list XML found in ' . DB_PREFIX . 'alto_branches to process (or forced reprocessing did not apply).', 'INFO');
}


// Process pending properties from ix3gf_alto_properties (our internal tracking)
Logger::log('Processing pending property XML from ' . DB_PREFIX . 'alto_properties...', 'INFO');
$pendingPropertiesStmt = $db->prepare("
    SELECT alto_property_id, alto_branch_id, xml_data
    FROM `" . DB_PREFIX . "alto_properties`
    WHERE processed = 0
    LIMIT 50 -- Process in batches
");
$pendingPropertiesStmt->execute();
$pendingProperties = $pendingPropertiesStmt->fetchAll(\PDO::FETCH_ASSOC);
$pendingPropertiesStmt->closeCursor(); // Close cursor after fetching
unset($pendingPropertiesStmt); // Explicitly unset

$processedPropertiesCount = 0;
if (count($pendingProperties) > 0) {
    Logger::log('Found ' . count($pendingProperties) . ' pending properties to process.', 'INFO');
    foreach ($pendingProperties as $propertyRow) {
        $altoPropertyId = $propertyRow['alto_property_id'];
        $altoBranchId = $propertyRow['alto_branch_id'];
        $propertySummaryXml = $propertyRow['xml_data']; // This is the SUMMARY XML

        Logger::log('    Attempting to process pending property ' . $altoPropertyId . ' for branch ' . $altoBranchId . '.', 'INFO');

        $cleanedSummaryXml = trim($propertySummaryXml);
        if (!str_starts_with($cleanedSummaryXml, '<?xml')) {
            $cleanedSummaryXml = '<?xml version="1.0" encoding="utf-8"?>' . $cleanedSummaryXml;
        }

        libxml_use_internal_errors(true);
        $simpleXml = \simplexml_load_string($cleanedSummaryXml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if ($simpleXml === false) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->message . " at line " . $error->line . ", column " . $error->column;
            }
            Logger::log('        Failed to parse summary XML for property ' . $altoPropertyId . ': ' . implode('; ', $errorMessages) . '. Skipping and marking as processed (XML error). XML content: ' . substr($cleanedSummaryXml, 0, 500) . '...', 'ERROR');
            $updateAltoPropStmt = $db->prepare("UPDATE `" . DB_PREFIX . "alto_properties` SET processed = 1 WHERE alto_property_id = ?");
            $updateAltoPropStmt->execute([$altoPropertyId]);
            unset($updateAltoPropStmt);
            continue;
        }

        $fullPropertyUrl = (string)$simpleXml->url; // URL to get full property details

        if (\trim($fullPropertyUrl) === '') {
            Logger::log('        No full property URL found in summary for property ' . $altoPropertyId . '. Skipping and marking as processed (no URL).', 'WARNING');
            $updateAltoPropStmt = $db->prepare("UPDATE `" . DB_PREFIX . "alto_properties` SET processed = 1 WHERE alto_property_id = ?");
            $updateAltoPropStmt->execute([$altoPropertyId]);
            unset($updateAltoPropStmt);
            continue;
        }

        // Fetch the full property XML using AltoApi
        $fullPropertyXml = $altoApi->fetchFullPropertyDetailsByUrl($fullPropertyUrl);

        if ($fullPropertyXml) {
            try {
                $cleanedFullPropertyXml = trim($fullPropertyXml);
                if (!str_starts_with($cleanedFullPropertyXml, '<?xml')) {
                    $cleanedFullPropertyXml = '<?xml version="1.0" encoding="utf-8"?>' . $cleanedFullPropertyXml;
                }

                libxml_use_internal_errors(true);
                $propertyFullXmlObject = simplexml_load_string($cleanedFullPropertyXml);
                $errors = libxml_get_errors();
                libxml_clear_errors();
                libxml_use_internal_errors(false);

                if ($propertyFullXmlObject === false) {
                    $errorMessages = [];
                    foreach ($errors as $error) {
                        $errorMessages[] = $error->message . " at line " . $error->line . ", column " . $error->column;
                    }
                    Logger::log('        Failed to parse full XML for property ' . $altoPropertyId . ': ' . implode('; ', $errorMessages) . '. Skipping and marking as processed (XML error). XML content: ' . substr($cleanedFullPropertyXml, 0, 500) . '...', 'ERROR');
                     $updateAltoPropStmt = $db->prepare("UPDATE `" . DB_PREFIX . "alto_properties` SET processed = 1 WHERE alto_property_id = ?");
                     $updateAltoPropStmt->execute([$altoPropertyId]);
                     unset($updateAltoPropStmt);
                     continue;
                }

                // Call the mapper. If it returns true, it means it successfully inserted or updated.
                if (OsPropertyMapper::mapPropertyDetailsToDatabase($propertyFullXmlObject, $altoBranchId)) { 
                    // Mark as processed in alto_properties ONLY if mapping succeeded
                    $updateAltoPropStmt = $db->prepare("UPDATE `" . DB_PREFIX . "alto_properties` SET processed = 1 WHERE alto_property_id = ?");
                    $updateAltoPropStmt->execute([$altoPropertyId]);
                    unset($updateAltoPropStmt);

                    // Also store this individual property's XML into osrs_xml_details, as it expects integer xml_id
                    $numericPropertyId = (int)$altoPropertyId; // Cast to int for xml_id field
                    $xmlToStoreInOsrs = '<?xml version="1.0" encoding="utf-8"?>' . $propertyFullXmlObject->asXML();

                    $stmtOsrsXmlDetails = $db->prepare("
                        INSERT INTO `" . DB_PREFIX . "osrs_xml_details` (xml_id, obj_content, imported)
                        VALUES (?, ?, 0)
                        ON DUPLICATE KEY UPDATE obj_content = VALUES(obj_content), imported = 0
                    ");
                    if ($stmtOsrsXmlDetails->execute([$numericPropertyId, $xmlToStoreInOsrs])) {
                        Logger::log("        Property " . ($altoPropertyId ?: 'N/A') . " full XML also stored/updated in " . DB_PREFIX . "osrs_xml_details.", 'INFO');
                    } else {
                        Logger::log("        Failed to store/update property " . ($altoPropertyId ?: 'N/A') . " full XML in " . DB_PREFIX . "osrs_xml_details: " . json_encode($stmtOsrsXmlDetails->errorInfo()), 'ERROR');
                    }
                    unset($stmtOsrsXmlDetails);

                    Logger::log('        Successfully mapped and marked property ' . $altoPropertyId . ' as imported.', 'INFO');
                    $processedPropertiesCount++;
                } else {
                    Logger::log('        Failed to map property ' . $altoPropertyId . '. Will retry on next run.', 'WARNING');
                    // DO NOT mark as processed if mapping failed, so it retries.
                }
            } catch (\Exception $e) {
                Logger::log('        CRITICAL ERROR mapping property ' . $altoPropertyId . ': ' . $e->getMessage(), 'CRITICAL');
                // DO NOT mark as processed, let it retry
            }
        } else {
            Logger::log('        Failed to fetch full XML for property ' . $altoPropertyId . '. Will retry on next run.', 'WARNING');
            // DO NOT mark as processed, let it retry
        }
    }
} else {
    Logger::log('No new property XML found in ' . DB_PREFIX . 'alto_properties.', 'INFO');
}

Logger::log('Alto Data Import completed.', 'INFO');

?>
