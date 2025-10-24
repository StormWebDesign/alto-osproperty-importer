<?php
/**
 * CLI: Show all available Alto property values (deeply nested) for inspection.
 *
 * Usage:
 *   php83 cli/alto-sync/show_property_details.php --id=34257827
 */

use AltoSync\AltoApi;
use AltoSync\Logger;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AltoApi.php';
require_once __DIR__ . '/Logger.php';

date_default_timezone_set('Europe/London');

// ----------------------------------------------------------------------
// CLI Argument
// ----------------------------------------------------------------------
$options = getopt('', ['id:']);
$propertyId = $options['id'] ?? null;
if (!$propertyId) {
    echo "Usage: php show_property_details.php --id=<alto_property_id>\n";
    exit(1);
}

// ----------------------------------------------------------------------
// Initialise and fetch property XML
// ----------------------------------------------------------------------
$tokenFile = __DIR__ . '/tokens.txt';
$api = new AltoApi($tokenFile);

$branchId = 8191; // Tudor EA branch
$url = "https://webservices.vebra.com/export/TudorEAAPI/v13/branch/{$branchId}/property/{$propertyId}";

Logger::log("Fetching property {$propertyId} for deep inspection...", 'INFO');

$xml = $api->callApi($url);
if (!$xml || trim($xml) === '') {
    Logger::log("No XML returned for property {$propertyId}", 'ERROR');
    exit(1);
}

$xmlFile = __DIR__ . "/xml/full_property_{$propertyId}.xml";
file_put_contents($xmlFile, $xml);
Logger::log("Full XML saved to {$xmlFile}", 'INFO');

// ----------------------------------------------------------------------
// Recursively display all nodes
// ----------------------------------------------------------------------
function printXmlTree(SimpleXMLElement $element, int $depth = 0)
{
    $indent = str_repeat('  ', $depth);
    foreach ($element->children() as $name => $child) {
        $value = trim((string)$child);
        if ($child->count() > 0) {
            echo "{$indent}- {$name}:\n";
            printXmlTree($child, $depth + 1);
        } else {
            echo "{$indent}- {$name}: {$value}\n";
        }
    }
}

echo "=============================================================\n";
echo " FULL PROPERTY DETAIL DUMP – Alto ID {$propertyId}\n";
echo "=============================================================\n\n";

$xmlObject = simplexml_load_string($xml);
printXmlTree($xmlObject);

echo "\n=============================================================\n";
echo "End of property dump.\n";
