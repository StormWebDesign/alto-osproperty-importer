<?php
// /public_html/cli/alto-sync/AltoApi.php

namespace AltoSync;

use AltoSync\Logger; // Ensure Logger is used

/**
 * Handles communication with the Alto API, including token management.
 */
class AltoApi
{
    private $baseUrl;
    private $username;
    private $password;
    private $tokenFile;
    private $accessToken = null;
    private $tokenExpiry = 0; // Unix timestamp

    public function __construct($tokenFile)
    {
        // Get constants from config.php
        $this->baseUrl = ALTO_API_BASE_URL;
        $this->username = ALTO_API_USERNAME;
        $this->password = ALTO_API_PASSWORD;
        $this->tokenFile = $tokenFile;

        Logger::log("AltoApi initialized with token file path: " . $this->tokenFile, 'DEBUG');

        // Attempt to load token on construction
        $this->loadTokenFromFile();
    }

    /**
     * Attempts to load the access token and its expiry from the token file.
     * @return bool True if a valid, non-expired token was loaded, false otherwise.
     */
    private function loadTokenFromFile()
    {
        Logger::log("Attempting to load token from file: " . $this->tokenFile, 'DEBUG');
        if (file_exists($this->tokenFile)) {
            $content = file_get_contents($this->tokenFile);
            $tokenData = json_decode($content, true);

            if ($tokenData && isset($tokenData['token']) && isset($tokenData['expiry'])) {
                $this->accessToken = $tokenData['token'];
                $this->tokenExpiry = $tokenData['expiry'];

                if ($this->isTokenValid()) {
                    Logger::log("Loaded valid JSON token from " . $this->tokenFile . ". Expires at " . date('Y-m-d H:i:s', $this->tokenExpiry), 'INFO');
                    return true;
                } else {
                    Logger::log("Token loaded from file is expired. Deleting token file.", 'INFO');
                    $this->deleteTokenFile();
                }
            } else {
                Logger::log("Invalid JSON format in token file. Deleting token file.", 'ERROR');
                $this->deleteTokenFile();
            }
        } else {
            Logger::log("Token file does not exist at " . $this->tokenFile, 'INFO');
        }
        $this->accessToken = null;
        $this->tokenExpiry = 0;
        return false;
    }

    /**
     * Saves the access token and its expiry to the token file.
     * @param string $token The access token.
     * @param int $expiry The Unix timestamp when the token expires.
     */
    private function saveTokenToFile($token, $expiry)
    {
        $tokenData = [
            'token' => $token,
            'expiry' => $expiry
        ];
        // Ensure the directory exists before saving
        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_put_contents($this->tokenFile, json_encode($tokenData))) {
            Logger::log("OAuth token saved to " . $this->tokenFile . ". Content (JSON): " . json_encode($tokenData), 'INFO');
        } else {
            Logger::log("Failed to save OAuth token to " . $this->tokenFile, 'ERROR');
        }
    }

    /**
     * Deletes the token file.
     */
    private function deleteTokenFile()
    {
        if (file_exists($this->tokenFile)) {
            unlink($this->tokenFile);
            Logger::log("Deleted token file: " . $this->tokenFile, 'INFO');
        }
    }

    /**
     * Checks if the currently held token is valid (not expired).
     * @return bool True if valid, false otherwise.
     */
    private function isTokenValid()
    {
        return $this->accessToken && ($this->tokenExpiry > time() + 60); // 60-second buffer
    }

    /**
     * Acquires a new access token using Basic Authentication (username:password).
     * This method is only called when no valid token is present or token expired.
     * @return string|false The new access token on success, false on failure.
     * @throws \Exception If token acquisition fails.
     */
    private function acquireNewToken()
    {
        Logger::log("No valid token found in memory or file. Attempting to acquire a new token...", 'INFO');
        $url = $this->baseUrl . 'branch'; // As per Alto documentation, initial token request is to /branch
        Logger::log("Attempting token acquisition from URL: " . $url, 'INFO');
        Logger::log("Using API Username: " . $this->username, 'DEBUG');

        $headers = [
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
            'Accept: application/xml'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true); // Get headers in response
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For debugging only, remove in production
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // For debugging only, remove in production


        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        curl_close($ch);

        Logger::log("Token Acquisition HTTP Code: " . $httpCode, 'INFO');
        Logger::log("Token Acquisition Curl Error: " . ($curlError ?: 'None'), 'INFO');
        Logger::log("Token Acquisition Response Headers:\n" . $responseHeaders, 'DEBUG');
        Logger::log("Token Acquisition Response Body (first 500 chars):\n" . substr($responseBody, 0, 500), 'DEBUG');

        if ($httpCode === 200 && strpos($responseHeaders, 'Token:') !== false) {
            // Extract token from response headers
            preg_match('/Token:\s*([a-zA-Z0-9]+)/', $responseHeaders, $matches);
            if (isset($matches[1])) {
                $newToken = $matches[1];
                // Assuming token is valid for 1 hour (3600 seconds) from acquisition
                $newExpiry = time() + 3600;

                $this->accessToken = $newToken;
                $this->tokenExpiry = $newExpiry;
                $this->saveTokenToFile($newToken, $newExpiry);
                Logger::log("Successfully acquired and saved new OAuth token.", 'INFO');
                return $newToken;
            }
        } elseif ($httpCode === 401) {
            Logger::log("Token acquisition failed. HTTP Code: 401. Your username/password might be incorrect or have insufficient permissions. Response: " . $responseBody, 'ERROR');
            // Do NOT delete token file here, as this is the *initial* acquisition attempt.
            // Only delete if a previously valid token became invalid.
        } else {
            Logger::log("Token acquisition failed. HTTP Code: " . $httpCode . ", Curl Error: " . $curlError . ". Check credentials and API availability.", 'ERROR');
        }

        throw new \Exception("Failed to acquire new OAuth token.");
    }

    /**
     * Public method to get a valid access token.
     * Ensures a valid token is returned, acquiring a new one if necessary.
     * @return string|false The valid access token, or false if unable to acquire.
     */
    public function getAccessToken()
    {
        Logger::log("Entering getAccessToken() method.", 'DEBUG');
        if (!$this->isTokenValid()) {
            Logger::log("No valid token in memory. Attempting to load from file or acquire new.", 'INFO');
            if (!$this->loadTokenFromFile() || !$this->isTokenValid()) {
                try {
                    return $this->acquireNewToken();
                } catch (\Exception $e) {
                    Logger::log("Failed to get OAuth token: " . $e->getMessage(), 'CRITICAL');
                    return false;
                }
            }
        }
        Logger::log("Using existing OAuth token from memory (expires: " . date('Y-m-d H:i:s', $this->tokenExpiry) . ").", 'INFO');
        return $this->accessToken;
    }

    /**
     * Makes an authenticated API call using the current access token.
     * If the token is invalid (receives a 401), it attempts to acquire a new one and retries the call.
     * @param string $endpoint The API endpoint (e.g., 'branch', or a full URL for property details/summaries).
     * @param string $branchId (DEPRECATED: No longer used for URL construction in this method).
     * @return string|false XML response string on success, false on failure.
     */
    public function callApi($endpoint, $branchId = null) // $branchId parameter is kept for compatibility but not used for URL construction
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            Logger::log("Cannot make API call: No valid access token.", 'CRITICAL');
            return false;
        }

        $url = '';
        if ($endpoint === 'branch') {
            $url = $this->baseUrl . 'branch';
        } elseif (filter_var($endpoint, FILTER_VALIDATE_URL)) {
            // If the endpoint itself is a full URL (e.g., for Get Property details or property list for branch)
            $url = $endpoint;
        } else {
            Logger::log("Invalid API endpoint provided: " . $endpoint . ". Expected 'branch' or a full URL.", 'ERROR');
            return false;
        }

        Logger::log("Calling API URL: " . $url, 'INFO');
        // The token string itself must be Base64 encoded for subsequent 'Basic' Authorization.
        $encodedTokenForBasicAuth = base64_encode($accessToken);
        Logger::log("Sending with Authorization header: Basic " . substr($encodedTokenForBasicAuth, 0, 20) . "...", 'DEBUG'); // Log masked token

        $headers = [
            'Authorization: Basic ' . $encodedTokenForBasicAuth, // FINAL FIX: Basic Auth with Base64 encoded token
            'Accept: application/xml'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true); // Get headers for error analysis
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For debugging only, remove in production
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // For debugging only, remove in production

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        curl_close($ch);

        Logger::log("API Call to " . $url . " - HTTP Code: " . $httpCode, 'INFO');
        Logger::log("API Call to " . $url . " - Curl Error: " . ($curlError ?: 'None'), 'INFO');
        Logger::log("API Call to " . $url . " - Response Body (first 500 chars):\n" . substr($responseBody, 0, 500), 'DEBUG');

        if ($httpCode === 200) {
            return $responseBody;
        } elseif ($httpCode === 401) {
            Logger::log("Access token possibly invalid for " . $url . ". Attempting to re-acquire token and retry.", 'WARNING');
            // If 401, token might have expired despite our check. Delete it and retry.
            $this->deleteTokenFile();
            $this->accessToken = null; // Clear in memory
            $this->tokenExpiry = 0; // Clear in memory
            
            // Recursive retry with a new token
            Logger::log("Retrying API call after token re-acquisition...", 'INFO');
            // Note: Recalling callApi with $endpoint as its original value
            // If $endpoint was a URL, it will be validated again. If it was 'branch', it will be built again.
            return $this->callApi($endpoint, $branchId); // Retry the original call
        } elseif ($httpCode === 403) {
            Logger::log("API call to " . $url . " returned 403 Forbidden. This indicates you have authenticated, but your account lacks permission for this specific endpoint/data.", 'ERROR');
            return false; 
        } else {
            Logger::log("API call to " . $url . " failed. HTTP Code: " . $httpCode . ", Curl Error: " . $curlError, 'ERROR');
            return false;
        }
    }

    /**
     * Fetches the initial list of branches.
     * @return string|false XML response or false on failure.
     */
    public function fetchBranchList()
    {
        Logger::log("1. Fetching branches list...", 'INFO');
        // AltoApi::callApi handles getting/refreshing token internally.
        $xml = $this->callApi('branch');
        if ($xml) {
            Logger::log("API call to branch list successful.", 'INFO');
        } else {
            Logger::log("Failed to retrieve branches XML from Alto API. Check API connection and token.", 'ERROR');
        }
        return $xml;
    }

    /**
     * Fetches property summaries using the full, specific URL for a branch's properties.
     * @param string $fullPropertyListUrl The complete URL to retrieve properties for a specific branch.
     * @return string|false XML response or false on failure.
     */
    public function fetchPropertySummariesByUrl($fullPropertyListUrl) // New method or updated
    {
        Logger::log("Fetching property summaries from URL: " . $fullPropertyListUrl, 'INFO');
        // The endpoint is the full URL itself.
        $xml = $this->callApi($fullPropertyListUrl); // Pass the full URL as the endpoint directly
        if ($xml) {
            Logger::log("Successfully retrieved property list from " . $fullPropertyListUrl . ".", 'INFO');
        } else {
            Logger::log("Failed to retrieve property list from " . $fullPropertyListUrl . ". Check API response and network.", 'ERROR');
        }
        return $xml;
    }

    /**
     * Fetches full property details using a direct URL (from property summary).
     * @param string $fullPropertyUrl The direct URL to the property details.
     * @return string|false XML response or false on failure.
     */
    public function fetchFullPropertyDetailsByUrl($fullPropertyUrl)
    {
        Logger::log("Fetching full property details from URL: " . $fullPropertyUrl, 'INFO');
        // The endpoint is the full URL itself.
        $xml = $this->callApi($fullPropertyUrl); // Pass the full URL as the endpoint
        if ($xml) {
            Logger::log("Successfully retrieved full property XML from " . $fullPropertyUrl . ".", 'INFO');
        } else {
            Logger::log("Failed to retrieve full property XML from " . $fullPropertyUrl . ". Check API response and network.", 'ERROR');
        }
        return $xml;
    }
}
