<?php
/**
 * reset_all_data.php
 * 
 * Completely clears OS Property import data so you can run a clean test.
 * Run via CLI only: php reset_all_data.php
 */

use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseFactory;
use Joomla\CMS\Factory;

require_once __DIR__ . '/config.php';

echo "------------------------------------------------------------\n";
echo "🧹  Resetting OS Property + Alto Importer Data\n";
echo "------------------------------------------------------------\n";

try {
    // Bootstrap Joomla database connection
    $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');

    $truncateTables = [
        'ix3gf_osrs_properties',
        'ix3gf_osrs_photos',
        'ix3gf_osrs_property_amenities',
        'ix3gf_osrs_amenities',
        'ix3gf_osrs_xml_details',
        'ix3gf_osrs_property_categories',
    ];

    foreach ($truncateTables as $table) {
        $query = "TRUNCATE TABLE `$table`";
        $db->setQuery($query)->execute();
        echo "✅ Truncated: $table\n";
    }

    // Reset Alto tracking tables
    $updates = [
        "UPDATE `ix3gf_alto_properties` SET processed = 0, last_synced = NULL",
        "UPDATE `ix3gf_alto_branches` SET last_synced = NULL"
    ];
    foreach ($updates as $sql) {
        $db->setQuery($sql)->execute();
        echo "🔄 Updated: $sql\n";
    }

    echo "------------------------------------------------------------\n";
    echo "🎯 Reset complete! You can now run:\n";
    echo "   php83 sync.php\n";
    echo "   php83 import.php\n";
    echo "------------------------------------------------------------\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
