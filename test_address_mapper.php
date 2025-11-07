<?php
/**
 * CLI Test Script for AddressMapper
 * ---------------------------------
 * Usage:
 *   php test_address_mapper.php xml/full_properties/34257827.xml
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Mapper/AddressMapper.php';

use AltoSync\Mapper\AddressMapper;
use AltoSync\Logger;

// ---------------------------------------------------------------------------
// Logging setup
// ---------------------------------------------------------------------------
Logger::init(__DIR__ . '/logs/test_address_mapper.log');
Logger::log("Starting AddressMapper test...", 'INFO');

// ---------------------------------------------------------------------------
// CLI argument check
// ---------------------------------------------------------------------------
if ($argc < 2) {
    echo "Usage: php test_address_mapper.php /path/to/property.xml\n";
    exit(1);
}

$xmlPath = $argv[1];
if (!file_exists($xmlPath)) {
    echo "❌ File not found: {$xmlPath}\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Load XML
// ---------------------------------------------------------------------------
$xml = simplexml_load_file($xmlPath);
if (!$xml || !isset($xml->address)) {
    echo "❌ Invalid XML or missing <address> node.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Connect to DB (same PDO config as importer)
// ---------------------------------------------------------------------------
try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    Logger::log("✅ Database connection established.", 'INFO');
} catch (Exception $e) {
    echo "❌ DB connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Run the mapper
// ---------------------------------------------------------------------------
$addressMapper = new AddressMapper($db);
$result = $addressMapper->mapAddress($xml->address, 'United Kingdom');

// ---------------------------------------------------------------------------
// Output results
// ---------------------------------------------------------------------------
echo "\n📄 File: {$xmlPath}\n";
echo "---------------------------------------------\n";
printf("Address:        %s\n", $result['address']);
printf("Full Address:   %s\n", $result['full']);
printf("City ID:        %s\n", $result['city_id']);
printf("State ID:       %s\n", $result['state_id']);
printf("Country ID:     %s\n", $result['country_id']);
printf("Postcode:       %s\n", $result['postcode']);
echo "---------------------------------------------\n";

Logger::log("✅ AddressMapper test complete for {$xmlPath}.", 'INFO');
echo "✅ Done. Check logs/test_address_mapper.log for details.\n";
