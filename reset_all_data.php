<?php
// /public_html/cli/alto-sync/reset_all_data.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';

use AltoSync\Logger;

echo "------------------------------------------------------------\n";
echo "🧹  Resetting OS Property + Alto Importer Data\n";
echo "------------------------------------------------------------\n";

try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $tables = [
        DB_PREFIX . 'osrs_properties',
        DB_PREFIX . 'osrs_photos',
        DB_PREFIX . 'osrs_property_categories',
        DB_PREFIX . 'alto_properties',
        DB_PREFIX . 'alto_branches'
    ];

    foreach ($tables as $table) {
        $db->exec("TRUNCATE TABLE `$table`");
        echo "✅ Truncated $table\n";
    }

    echo "------------------------------------------------------------\n";
    echo "✅ All OS Property + Alto Importer tables cleared successfully\n";
    echo "------------------------------------------------------------\n";
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    Logger::log("Reset failed: " . $e->getMessage(), 'CRITICAL');
    exit(1);
}
