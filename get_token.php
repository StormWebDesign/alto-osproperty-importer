<?php
// /public_html/cli/alto-sync/get_token.php - Script to acquire and store a new Alto API token hourly.

namespace AltoSync; // Declare namespace for consistency

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/AltoApi.php';

use AltoSync\Logger;
use AltoSync\AltoApi;

// --- Logging Setup ---
Logger::init(__DIR__ . '/logs/get_token.log'); // Dedicated log for this script
Logger::log("Starting token acquisition process...", 'INFO');

// Delete the existing token file to force AltoApi to acquire a new one.
// This is done by `get_token.php` specifically to ensure a fresh token each hour.
if (file_exists(\TOKEN_FILE)) {
    unlink(\TOKEN_FILE);
    Logger::log("Deleted existing token file: " . \TOKEN_FILE, 'INFO');
} else {
    Logger::log("No existing token file found. Proceeding to acquire new token.", 'INFO');
}

// Instantiate AltoApi and acquire token. AltoApi handles the logic internally.
$api = new AltoApi(\TOKEN_FILE);
$accessToken = $api->getAccessToken();

if ($accessToken) {
    Logger::log("Successfully acquired and stored new token.", 'INFO');
    exit("SUCCESS: New token acquired and saved.\n");
} else {
    Logger::log("Failed to acquire new token during cron run.", 'ERROR');
    exit("ERROR: Failed to acquire token.\n");
}
?>
