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
