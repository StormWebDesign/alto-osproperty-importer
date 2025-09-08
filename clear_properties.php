<?php
//
// How to use
//
// Fully reset + reimport OS Property data and photos from Alto.
// /usr/bin/php83 /public_html/cli/alto-sync/clear_properties.php

// Non-interactive (good for maintenance windows):
// /usr/bin/php83 /public_html/cli/alto-sync/clear_properties.php --yes

// Skip re-scanning the filesystem for photos (rare):
// /usr/bin/php83 /public_html/cli/alto-sync/clear_properties.php --yes --no-photo-scan

// Skip thumbnail/medium backfill (if you only trust ImportPhotosFromFS to create derivatives):
// /usr/bin/php83 /public_html/cli/alto-sync/clear_properties.php --yes --no-backfill

// Preserve #__osrs_xml_details (if you’re using it for historical diffs):
// /usr/bin/php83 /public_html/cli/alto-sync/clear_properties.php --yes --keep-xml

// Preserve #__osrs_photos table (e.g., debugging):
// /usr/bin/php83 /public_html/cli/alto-sync/clear_properties.php --yes --keep-photos-table

// Preserve the image folders
// /usr/bin/php83 /public_html/cli/alto-sync/clear_properties.php --yes --no-fs-clear

declare(strict_types=1);

require_once __DIR__ . '/config.php';
date_default_timezone_set('UTC');

function logln(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}
function fail(string $msg, int $code = 1): void {
    logln("ERROR: $msg");
    exit($code);
}
function getArg(string $name, ?string $default = null): ?string {
    global $argv;
    foreach ($argv as $a) {
        if (strpos($a, $name.'=') === 0) {
            return substr($a, strlen($name) + 1);
        }
    }
    return $default;
}
function hasFlag(string $flag): bool {
    global $argv;
    return in_array($flag, $argv, true);
}

$phpBin = getArg('--php', PHP_BINARY);
$skipPhotoScan = hasFlag('--no-photo-scan');
$skipBackfill  = hasFlag('--no-backfill');
$keepXml       = hasFlag('--keep-xml');
$keepPhotosTab = hasFlag('--keep-photos-table');
$skipFS        = hasFlag('--no-fs-clear'); // optional, skip deleting images on disk

if (!hasFlag('--yes')) {
    echo "This will TRUNCATE staging + OS Property tables and DELETE all images in /images/osproperty/properties.\n";
    echo "Tables cleared:\n";
    echo "  - " . DB_PREFIX . "alto_branches\n";
    echo "  - " . DB_PREFIX . "alto_properties\n";
    echo "  - " . DB_PREFIX . "osrs_properties\n";
    if (!$keepPhotosTab) echo "  - " . DB_PREFIX . "osrs_photos\n";
    if (!$keepXml)       echo "  - " . DB_PREFIX . "osrs_xml_details\n";
    echo "Filesystem cleared:\n";
    if (!$skipFS) echo "  - /images/osproperty/properties/*\n";
    echo "\nContinue? Type 'YES' to proceed: ";
    $confirm = trim(fgets(STDIN) ?: '');
    if ($confirm !== 'YES') {
        echo "Aborted.\n";
        exit(0);
    }
}

logln("Starting full reset + import");

// 1) DB connection
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    logln("DB connected to " . DB_NAME);
} catch (Throwable $e) {
    fail('DB connect error: ' . $e->getMessage());
}

// 2) TRUNCATE tables
$tables = [
    DB_PREFIX . 'alto_branches',
    DB_PREFIX . 'alto_properties',
    DB_PREFIX . 'osrs_properties',
];
if (!$keepPhotosTab) {
    $tables[] = DB_PREFIX . 'osrs_photos';
}
if (!$keepXml) {
    $tables[] = DB_PREFIX . 'osrs_xml_details';
}

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) {
        logln("TRUNCATE $t ...");
        $pdo->exec("TRUNCATE TABLE `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    logln("Truncate complete.");
} catch (Throwable $e) {
    fail('Truncate error: ' . $e->getMessage());
}

// 3) Delete filesystem image folders
$baseFS = rtrim(PROPERTY_IMAGE_UPLOAD_BASE_PATH ?? '', '/');
if (!$skipFS && $baseFS && is_dir($baseFS)) {
    logln("Clearing filesystem under: $baseFS");
    $it = new RecursiveDirectoryIterator($baseFS, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    logln("Filesystem cleared.");
} else {
    logln("Skipping filesystem clear (--no-fs-clear or invalid path)");
}

// Helper to run a child command and stream output
function runCmd(string $cmd): void {
    logln("Running: $cmd");
    $tmp = tempnam(sys_get_temp_dir(), 'rc_');
    $wrapped = $cmd . ' ; echo $? > ' . escapeshellarg($tmp);
    passthru($wrapped);
    $rc = (int)trim(@file_get_contents($tmp) ?: '1');
    @unlink($tmp);
    if ($rc !== 0) {
        fail("Command failed (rc=$rc): $cmd", $rc);
    }
}

// Paths to scripts
$base = __DIR__;
$sync      = escapeshellarg($phpBin) . ' ' . escapeshellarg($base . '/sync.php');
$import    = escapeshellarg($phpBin) . ' ' . escapeshellarg($base . '/import.php');
$photos    = escapeshellarg($phpBin) . ' ' . escapeshellarg($base . '/ImportPhotosFromFS.php') . ' --all --reorder --prune';
$backfill  = escapeshellarg($phpBin) . ' ' . escapeshellarg($base . '/BackfillOsPropertyImages.php') . ' --all';

// 4) Run sync
runCmd($sync);

// 5) Run import
runCmd($import);

// 6) Rebuild osrs_photos
if (!$skipPhotoScan) {
    runCmd($photos);
} else {
    logln("Skipping photo scan (--no-photo-scan)");
}

// 7) Backfill thumbnails
if (!$skipBackfill) {
    runCmd($backfill);
} else {
    logln("Skipping backfill (--no-backfill)");
}

logln("All done. 🎉");
exit(0);
