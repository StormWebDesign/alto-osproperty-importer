<?php
// /public_html/cli/alto-sync/get_token.php
// Safely acquire and store a new Alto API token only when needed (avoids hourly 401s).

namespace AltoSync;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/AltoApi.php';

use AltoSync\Logger;
use AltoSync\AltoApi;

Logger::init(__DIR__ . '/logs/get_token.log');
Logger::log("Starting token acquisition process...", 'INFO');

/**
 * Read and parse the token file (JSON). Returns array or null.
 */
function readTokenFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        return $data;
    }

    // If the file isn't JSON for some reason, treat as missing
    return null;
}

try {
    // 1) Check existing token file first (without using AltoApi private methods)
    $existing = readTokenFile(TOKEN_FILE);

    if ($existing) {
        $now = time();
        $remaining = null;

        if (!empty($existing['expires_at'])) {
            $expiryTs = strtotime($existing['expires_at']);
            if ($expiryTs !== false) {
                $remaining = $expiryTs - $now;
            }
        } elseif (!empty($existing['expires_in']) && !empty($existing['fetched_at'])) {
            // Fallback: compute expiry from fetched_at + expires_in if provided
            $fetchedTs = strtotime($existing['fetched_at']);
            if ($fetchedTs !== false) {
                $expiryTs = $fetchedTs + (int)$existing['expires_in'];
                $remaining = $expiryTs - $now;
            }
        }

        if ($remaining !== null && $remaining > 60) {
            Logger::log(
                "Existing token still valid for ~" . round($remaining / 60) . " minute(s) — skipping refresh.",
                'INFO'
            );
            echo "SKIPPED: Token still valid.\n";
            exit(0);
        }
    } else {
        Logger::log("No existing token file found — will acquire a new token.", 'INFO');
    }

    // 2) Token is missing or expiring — acquire a fresh one
    if (file_exists(TOKEN_FILE)) {
        @unlink(TOKEN_FILE);
        Logger::log("Deleted old/expired token file: " . TOKEN_FILE, 'INFO');
    }

    $api = new AltoApi(TOKEN_FILE);
    $accessToken = $api->getAccessToken(); // AltoApi handles Basic auth & token persistence

    if ($accessToken) {
        Logger::log("✅ Successfully acquired and stored new token.", 'INFO');

        // Re-read the token file to log the actual expiry time saved by AltoApi
        $saved = readTokenFile(TOKEN_FILE);
        if ($saved && !empty($saved['expires_at'])) {
            Logger::log("New token expires at: {$saved['expires_at']}", 'INFO');
        }

        echo "SUCCESS: New token acquired and saved.\n";
        exit(0);
    } else {
        Logger::log("❌ Failed to acquire new token.", 'ERROR');
        echo "ERROR: Failed to acquire token.\n";
        exit(1);
    }

} catch (\Throwable $e) {
    Logger::log("CRITICAL: Exception during token acquisition — " . $e->getMessage(), 'CRITICAL');
    echo "ERROR: Exception occurred — see logs for details.\n";
    exit(1);
}
