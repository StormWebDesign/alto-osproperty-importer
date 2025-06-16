<?php

namespace AltoSync\Utils;

/**
 * Helper class for making cURL requests.
 */
class CurlHelper
{

    /**
     * Makes a GET request to the specified URL.
     *
     * @param string $url The URL to request.
     * @param array $headers An array of HTTP headers to send.
     * @param bool $returnHeaders Whether to include response headers in the output.
     * @param string|null $headerFile Path to a file to write response headers to.
     * @return array An associative array containing 'http_code', 'body', and 'headers' (if requested).
     */
    public function get($url, array $headers = [], $returnHeaders = false, $headerFile = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); // Set HTTP headers
        curl_setopt($ch, CURLOPT_FAILONERROR, false); // Don't fail on HTTP errors (e.g., 404, 500)
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects

        // --- START DEBUGGING ADDITIONS ---
        // For verbose cURL logging, temporarily redirect to a file.
        // Ensure LOGS_DIR is correctly defined and accessible for CurlHelper.
        // You might need to adjust path if LOGS_DIR is not directly available here.
        // Given sync.php includes config.php which defines LOGS_DIR, it should be fine.
        $verboseLogFile = LOGS_DIR . 'curl_debug.log';
        $verboseLog = fopen($verboseLogFile, 'a+'); // Open in append mode
        if ($verboseLog) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_STDERR, $verboseLog);
        } else {
            error_log("Failed to open curl_debug.log for verbose output: " . $verboseLogFile);
        }
        // --- END DEBUGGING ADDITIONS ---

        $responseHeaders = '';
        if ($returnHeaders) {
            curl_setopt($ch, CURLOPT_HEADER, true); // Include header in output
            if ($headerFile) {
                // Open a file for writing headers
                $fp = fopen($headerFile, 'w+');
                if ($fp === false) {
                    error_log("Failed to open header file for writing: " . $headerFile);
                    // Fallback to internal memory if file cannot be opened
                    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
                        $responseHeaders .= $header;
                        return strlen($header);
                    });
                } else {
                    curl_setopt($ch, CURLOPT_WRITEHEADER, $fp); // Write headers to file
                }
            } else {
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
                    $responseHeaders .= $header;
                    return strlen($header);
                });
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($headerFile && isset($fp) && $fp !== false) {
            fclose($fp); // Close the header file handle
            // Read headers back from file if they were written there
            $responseHeaders = file_get_contents($headerFile);
        }

        // Close verbose log file if opened
        if (isset($verboseLog) && $verboseLog) {
            fclose($verboseLog);
        }

        curl_close($ch);

        if ($curlError) {
            error_log("cURL Error: " . $curlError . " for URL: " . $url);
            return [
                'http_code' => 0, // Indicate cURL error
                'body'      => 'cURL Error: ' . $curlError,
                'headers'   => ''
            ];
        }

        if ($returnHeaders) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $responseBody = substr($response, $headerSize);
            // $responseHeaders is already collected by CURLOPT_HEADERFUNCTION or from file
        } else {
            $responseBody = $response;
            $responseHeaders = ''; // No headers requested
        }

        return [
            'http_code' => $httpCode,
            'body'      => $responseBody,
            'headers'   => $responseHeaders
        ];
    }
}
