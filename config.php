<?php
// config.php

// Alto API Credentials
define('ALTO_API_USERNAME', 'Tudor535ADxml');
define('ALTO_API_PASSWORD', 'D9q1Jb4B54ClC4e');
define('ALTO_API_DATAFEED_ID', 'TudorEAAPI');
define('ALTO_API_BASE_URL', 'https://webservices.vebra.com/export/'); // Base URL for the Vebra/Alto API

// MySQL Database Credentials
define('DB_HOST', 'sdb-80.hosting.stackcp.net');
define('DB_PORT', 3306);
define('DB_NAME', 'joomla5-353038353e37');
define('DB_USER', 'joomla5-353038353e37');
define('DB_PASS', 'm7l60up05k');
define('DB_PREFIX', 'ix3gf_'); // Joomla table prefix

// File Paths
define('LOGS_DIR', __DIR__ . '/logs/'); // Directory for log files (headers.txt, tokens.txt, test.xml)
define('TOKENS_FILE', __DIR__ . '/tokens.txt');
define('HEADERS_FILE', LOGS_DIR . 'headers.txt');
define('API_RESPONSE_XML_FILE', LOGS_DIR . 'api_response.xml');
define('BRANCHES_LIST_XML_FILE', LOGS_DIR . 'branches_list.xml');

// Ensure the logs directory exists
if (!is_dir(LOGS_DIR)) {
    mkdir(LOGS_DIR, 0755, true);
}
?>