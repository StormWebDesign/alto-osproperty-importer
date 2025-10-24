<?php
// show_token.php
// -----------------------------------------------------------------------------
// Simple utility to read and display the current Alto API token info.
// -----------------------------------------------------------------------------

require_once __DIR__ . '/config.php';

$tokenFile = TOKEN_FILE;

if (!file_exists($tokenFile)) {
    echo "❌ No token file found at: {$tokenFile}\n";
    exit(1);
}

$tokenData = json_decode(file_get_contents($tokenFile), true);

if (!$tokenData || !isset($tokenData['access_token'])) {
    echo "⚠️ Token file exists but is invalid or incomplete.\n";
    exit(1);
}

echo "✅ Current Alto API Token Details\n";
echo "-------------------------------------------\n";
echo "Access Token: " . substr($tokenData['access_token'], 0, 30) . "...\n";
echo "Expires At:   " . ($tokenData['expires_at'] ?? 'Unknown') . "\n";

if (isset($tokenData['expires_at'])) {
    $expiry = strtotime($tokenData['expires_at']);
    $remaining = $expiry - time();
    if ($remaining > 0) {
        $minutes = floor($remaining / 60);
        echo "Time Remaining: {$minutes} minutes\n";
    } else {
        echo "⚠️ Token has already expired.\n";
    }
}
echo "-------------------------------------------\n";
?>
