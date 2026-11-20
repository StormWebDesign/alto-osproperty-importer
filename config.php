<?php
// /public_html/cli/alto-sync/config.php
//
// No credentials are stored in this file.
//   - Database credentials are read from Joomla's own configuration.php.
//   - Alto API credentials live in config.local.php (not in git) –
//     copy config.local.php.example to config.local.php and fill it in.
//
// config.local.php is loaded first, so it can also override any constant
// below (e.g. JOOMLA_ROOT or the DB_* values when running off the server).

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

// Joomla root – this folder lives in <joomla>/cli/alto-sync/
if (!defined('JOOMLA_ROOT')) {
    define('JOOMLA_ROOT', dirname(__DIR__, 2));
}

// Database Configuration (from Joomla's configuration.php)
if (!defined('DB_HOST')) {
    $joomlaConfigFile = JOOMLA_ROOT . '/configuration.php';
    if (!is_file($joomlaConfigFile)) {
        error_log("config.php: Joomla configuration not found at {$joomlaConfigFile}. Set JOOMLA_ROOT or the DB_* constants in config.local.php.");
        exit(1);
    }

    if (!defined('_JEXEC')) {
        define('_JEXEC', 1); // configuration.php may contain a _JEXEC guard
    }
    if (!class_exists('JConfig', false)) {
        require_once $joomlaConfigFile;
    }
    $jConfig = new JConfig();

    // Joomla allows "host:port" in $host
    $dbHost = (string) $jConfig->host;
    $dbPort = 3306;
    if (preg_match('/^(.+):(\d+)$/', $dbHost, $m)) {
        $dbHost = $m[1];
        $dbPort = (int) $m[2];
    }

    define('DB_HOST', $dbHost);
    define('DB_PORT', $dbPort);
    define('DB_NAME', (string) $jConfig->db);
    define('DB_USER', (string) $jConfig->user);
    define('DB_PASS', (string) $jConfig->password);
    define('DB_PREFIX', (string) $jConfig->dbprefix); // Joomla table prefix (ix3gf_)
    unset($jConfig, $dbHost, $dbPort, $m);
}

// Alto API Credentials (defined in config.local.php)
if (!defined('ALTO_API_USERNAME')) {
    define('ALTO_API_USERNAME', ''); // Your Alto API username for Basic Auth
}
if (!defined('ALTO_API_PASSWORD')) {
    define('ALTO_API_PASSWORD', ''); // Your Alto API password for Basic Auth
}
if (!defined('ALTO_API_BASE_URL')) {
    define('ALTO_API_BASE_URL', 'https://webservices.vebra.com/export/TudorEAAPI/v13/'); // ALTO_API_BASE_URL must include the datafeed ID and version as part of the base path
}
if (!defined('ALTO_API_DATAFEED_ID')) {
    define('ALTO_API_DATAFEED_ID', 'TudorEAAPI'); // Your Alto Datafeed ID (part of the API path)
}

// File Paths
define('LOGS_DIR', __DIR__ . '/logs/');
define('TOKEN_FILE', __DIR__ . '/tokens.txt'); // Path to store the OAuth token

// Log file path (relative to the script's directory)
define('LOG_FILE_SYNC', __DIR__ . '/logs/alto-sync.log');
define('LOG_FILE_IMPORT', __DIR__ . '/logs/alto-import.log');

// --- Image Upload Configuration (works in CLI & web) ---
// This is the absolute base path on your server where OS Property stores property images.
// OS Property creates subfolders for each property ID.
$docRootEnv = getenv('DOC_ROOT') ?: '';
$docRootSrv = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';

$docRoot = $docRootEnv ?: ($docRootSrv ?: JOOMLA_ROOT);

// Final absolute filesystem path (keep trailing slash)
define(
    'PROPERTY_IMAGE_UPLOAD_BASE_PATH',
    rtrim($docRoot, '/') . '/images/osproperty/properties/'
);

// DB path (stored in #__osrs_photos.image) — relative to images/osproperty/
define('PROPERTY_IMAGE_DB_BASE_PATH_PREFIX', 'properties/');
