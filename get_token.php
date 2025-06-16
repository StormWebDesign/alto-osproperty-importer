<?php

// get_token.php - Script to acquire and store a new Alto API token hourly.

// Configuration
$token_file_path = __DIR__ . '/tokens.txt'; // Path to store the token
$username = 'Tudor535ADxml'; // Your API Username
$password = 'D9q1Jb4B54ClC4e'; // Your API Password
$api_token_endpoint = 'https://webservices.vebra.com/export/TudorEAAPI/v13/branch'; // Endpoint for token acquisition

// --- Logging Function ---
function log_message($message, $level = 'INFO') {
    $log_file = __DIR__ . '/logs/get_token.log'; // Dedicated log for this script
    $timestamp = date('Y-m-d H:i:s e');
    file_put_contents($log_file, "[$timestamp] [$level] $message\n", FILE_APPEND);
}

log_message("Starting token acquisition process...");

// 1. Delete the existing token file (to ensure we request a new one)
if (file_exists($token_file_path)) {
    unlink($token_file_path);
    log_message("Deleted existing token file: " . $token_file_path);
} else {
    log_message("No existing token file found. Proceeding to acquire new token.");
}

// 2. Connect to the API and get a new token
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $api_token_endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);    // Return the response as a string
curl_setopt($ch, CURLOPT_HEADER, true);          // Include headers in the output
curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password); // Set Basic Authentication
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/xml']); // Request XML response
curl_setopt($ch, CURLOPT_VERBOSE, true);        // Enable verbose output for debugging
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Verify SSL certificate
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);    // Verify hostname against certificate

// Capture cURL verbose output to a file for debugging
$verbose_log_file = __DIR__ . '/logs/get_token_curl_debug.log';
$verbose_log = fopen($verbose_log_file, 'w+'); // Overwrite each time
curl_setopt($ch, CURLOPT_STDERR, $verbose_log);

log_message("Attempting to acquire new token using Basic Auth to " . $api_token_endpoint);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $error_message = "cURL Error during token acquisition: " . curl_error($ch);
    log_message($error_message, 'ERROR');
    fclose($verbose_log);
    curl_close($ch);
    exit("ERROR: " . $error_message . "\n");
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $header_size);
$body = substr($response, $header_size);

fclose($verbose_log);
log_message("Response Headers from token acquisition:\n" . $headers);
log_message("Response Body from token acquisition:\n" . $body);

if ($http_code === 200) {
    log_message("Successfully received HTTP 200 OK for token acquisition.");
    // Attempt to extract the Token
    $token = null;
    if (preg_match('/Token:\s*([a-zA-Z0-9\/+=]+)/i', $headers, $matches)) {
        $token = $matches[1];
    }

    if ($token) {
        // 3. Create/update tokens.txt with the new token details
        file_put_contents($token_file_path, $token);
        log_message("Successfully acquired and stored new token.");
        exit("SUCCESS: New token acquired and saved.\n");
    } else {
        log_message("HTTP 200 OK, but 'Token:' header not found in response. This is unexpected.", 'ERROR');
        exit("ERROR: No token header found despite 200 OK.\n");
    }
} else {
    log_message("Failed to acquire token. HTTP Code: " . $http_code . ". Response indicates an issue.", 'ERROR');
    exit("ERROR: Failed to acquire token. HTTP Code: " . $http_code . "\n");
}

curl_close($ch);

?>