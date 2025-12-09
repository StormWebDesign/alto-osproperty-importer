<?php
// cleanup_property_images.php
// Deletes all property image folders except medium, panorama, thumb, and index.html
// Includes full logging via your existing Logger class

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';

use AltoSync\Logger;

Logger::init('cleanup_property_images'); // log file: logs/cleanup_property_images_YYYY-MM-DD.log

$base = realpath(__DIR__ . '/../../images/osproperty/properties');

$keep = [
    'medium',
    'panorama',
    'thumb',
    'index.html'
];

Logger::log("------------------------------------------------------------", 'INFO');
Logger::log("🧹 Starting OS Property image cleanup", 'INFO');
Logger::log("Base directory: $base", 'INFO');
Logger::log("------------------------------------------------------------", 'INFO');

foreach (scandir($base) as $item) {
    if ($item === '.' || $item === '..') {
        continue;
    }

    if (in_array($item, $keep, true)) {
        Logger::log("SKIP: $item (protected)", 'INFO');
        continue;
    }

    $path = $base . '/' . $item;

    if (is_dir($path)) {
        Logger::log("DELETE DIR: $item", 'INFO');
        exec('rm -rf ' . escapeshellarg($path));
    } else {
        Logger::log("DELETE FILE: $item", 'INFO');
        unlink($path);
    }
}

Logger::log("Cleanup completed.", 'INFO');
