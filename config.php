<?php
// /public_html/cli/alto-sync/config.php

// Database Configuration
define('DB_HOST', 'sdb-80.hosting.stackcp.net');
define('DB_PORT', 3306);
define('DB_NAME', 'joomla5-353038353e37');
define('DB_USER', 'joomla5-353038353e37');
define('DB_PASS', 'm7l60up05k');
define('DB_PREFIX', 'ix3gf_'); // Joomla table prefix

// Alto API Credentials
define('ALTO_API_USERNAME', 'Tudor535ADxml'); // Your Alto API username for Basic Auth
define('ALTO_API_PASSWORD', 'D9q1Jb4B54ClC4e'); // Your Alto API password for Basic Auth
define('ALTO_API_BASE_URL', 'https://webservices.vebra.com/export/TudorEAAPI/v13/'); // ALTO_API_BASE_URL must include the datafeed ID and version as part of the base path
define('ALTO_API_DATAFEED_ID', 'TudorEAAPI'); // Your Alto Datafeed ID (part of the API path)

// File Paths
define('LOGS_DIR', __DIR__ . '/logs/');
define('TOKEN_FILE', __DIR__ . '/tokens.txt'); // Path to store the OAuth token

// Log file path (relative to the script's directory)
define('LOG_FILE_SYNC', __DIR__ . '/logs/alto-sync.log');
define('LOG_FILE_IMPORT', __DIR__ . '/logs/alto-import.log');

// --- NEW: Image Upload Configuration (Dynamic Paths) ---
// This is the absolute base path on your server where OS Property stores property images.
// OS Property typically creates subfolders for each property ID.
// Filesystem (absolute path) — where files are saved:
// Resolve document root for both CLI and web runtimes
// --- Image Upload Configuration (works in CLI & web) ---
$docRootGuess = realpath(__DIR__ . '/../../'); // -> /home/.../public_html
$docRootEnv   = getenv('DOC_ROOT') ?: '';
$docRootSrv   = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';

$docRoot = $docRootEnv ?: ($docRootSrv ?: $docRootGuess);

// Final absolute filesystem path (keep trailing slash)
define(
    'PROPERTY_IMAGE_UPLOAD_BASE_PATH',
    rtrim($docRoot, '/') . '/images/osproperty/properties/'
);

// DB path (stored in #__osrs_photos.image) — relative to images/osproperty/
define('PROPERTY_IMAGE_DB_BASE_PATH_PREFIX', 'properties/');
