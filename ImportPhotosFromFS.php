<?php
// ImportPhotosFromFS.php
// Scan /images/osproperty/properties/<pid>/ for original files (not thumb/medium)
// and ensure matching rows exist in #__osrs_photos (filename-only in `image`).
// Ordering is assigned by natural-sort of filenames (stable).
// Usage:
//   php ImportPhotosFromFS.php --pid=446 [--dry-run]
//   php ImportPhotosFromFS.php --all [--dry-run] [--reorder] [--prune]
//
//  --reorder : reset ordering 1..N by filename for properties we touch
//  --prune   : delete DB rows whose source file no longer exists
//  --dry-run : show what would change, but do not write

declare(strict_types=1);

require_once __DIR__ . '/config.php';
date_default_timezone_set('UTC');

function logln(string $m): void { echo '['.date('Y-m-d H:i:s')."] $m\n"; }

try {
    $db = new PDO(
        'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
        \DB_USER,
        \DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $db->exec('SET NAMES utf8mb4');
} catch (Throwable $e) {
    logln('DB connect error: ' . $e->getMessage());
    exit(1);
}

/** CLI args */
$pid = null;
$runAll = false;
$dry = false;
$reorder = false;
$prune = false;
foreach ($argv as $arg) {
    if (preg_match('/^--pid=(\d+)$/', $arg, $m)) $pid = (int)$m[1];
    elseif ($arg === '--all') $runAll = true;
    elseif ($arg === '--dry-run') $dry = true;
    elseif ($arg === '--reorder') $reorder = true;
    elseif ($arg === '--prune') $prune = true;
}
if (!$runAll && !$pid) {
    logln("Usage: php ImportPhotosFromFS.php --pid=<propertyId> [--dry-run|--reorder|--prune]  OR  --all [--dry-run|--reorder|--prune]");
    exit(2);
}

/** Resolve filesystem base */
$FS_BASE = rtrim(\PROPERTY_IMAGE_UPLOAD_BASE_PATH ?? '', '/'); // absolute
if ($FS_BASE === '' || !is_dir($FS_BASE)) {
    $webRootGuess = realpath(__DIR__ . '/../../'); // /public_html
    $FS_BASE = $webRootGuess . '/images/osproperty/properties';
}
if (!is_dir($FS_BASE)) {
    logln("ERROR: Image base dir not found: $FS_BASE");
    exit(1);
}
logln("FS base: $FS_BASE");
logln('Dry run: ' . ($dry ? 'yes' : 'no') . ' | Reorder: ' . ($reorder ? 'yes' : 'no') . ' | Prune: ' . ($prune ? 'yes' : 'no'));

/** Helpers */
function listOriginals(string $propDir): array {
    // list files in propDir that are NOT in thumb/medium and not hidden; only original images
    if (!is_dir($propDir)) return [];
    $files = [];
    $dh = opendir($propDir);
    if (!$dh) return [];
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        $abs = $propDir . '/' . $f;
        if (is_dir($abs)) continue; // skip thumb/medium dirs
        // only image-like extensions
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) continue;
        $files[] = $f; // store filename only
    }
    closedir($dh);
    // natural sort for human order; keep stable
    natcasesort($files);
    return array_values($files);
}

/** Load DB photos for a property: returns [ filename => [id, ordering] ] */
function loadDbPhotos(PDO $db, int $pid): array {
    $stmt = $db->prepare("SELECT id, image, ordering FROM `" . \DB_PREFIX . "osrs_photos` WHERE pro_id = ? ORDER BY ordering ASC, id ASC");
    $stmt->execute([$pid]);
    $map = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(string)$r['image']] = ['id' => (int)$r['id'], 'ordering' => (int)$r['ordering']];
    }
    return $map;
}

/** Insert one photo row (minimal required columns). */
function insertPhoto(PDO $db, int $pid, string $filename, int $ordering, bool $dry): ?int {
    logln("  [+] add DB photo: image='{$filename}', ordering={$ordering}");
    if ($dry) return null;
    $stmt = $db->prepare("INSERT INTO `" . \DB_PREFIX . "osrs_photos` (pro_id, image, ordering) VALUES (?, ?, ?)");
    $stmt->execute([$pid, $filename, $ordering]);
    return (int)$db->lastInsertId();
}

/** Update ordering for an existing row */
function updateOrdering(PDO $db, int $id, int $ordering, bool $dry): void {
    logln("  [~] set ordering id={$id} -> {$ordering}");
    if ($dry) return;
    $stmt = $db->prepare("UPDATE `" . \DB_PREFIX . "osrs_photos` SET ordering = ? WHERE id = ?");
    $stmt->execute([$ordering, $id]);
}

/** Delete a DB row */
function deletePhoto(PDO $db, int $id, string $filename, bool $dry): void {
    logln("  [-] prune DB photo id={$id} image='{$filename}' (file missing)");
    if ($dry) return;
    $stmt = $db->prepare("DELETE FROM `" . \DB_PREFIX . "osrs_photos` WHERE id = ?");
    $stmt->execute([$id]);
}

/** Determine pids to process */
$pids = [];
if ($runAll) {
    // any directory that is numeric under FS_BASE, or any pro_id present in osrs_properties
    // Prefer DB-driven so we stay aligned with imported properties.
    $rs = $db->query("SELECT id FROM `" . \DB_PREFIX . "osrs_properties` ORDER BY id");
    $pids = array_map(fn($r) => (int)$r['id'], $rs->fetchAll(PDO::FETCH_ASSOC));
    logln('Processing ALL properties from DB list: '.count($pids).' IDs');
} else {
    $pids = [$pid];
    logln("Processing single property: {$pid}");
}

$totalAdds = $totalUpdates = $totalPruned = 0;

foreach ($pids as $pp) {
    $propDir = $FS_BASE . '/' . $pp;
    $files = listOriginals($propDir);
    if (!$files) {
        // Optionally prune anything still in DB if asked
        if ($prune) {
            $dbMap = loadDbPhotos($db, $pp);
            foreach ($dbMap as $fname => $info) {
                deletePhoto($db, $info['id'], $fname, $dry);
                $totalPruned++;
            }
        }
        continue;
    }

    logln("PID={$pp} originals on disk: ".count($files));

    // current DB state
    $dbMap = loadDbPhotos($db, $pp);

    // ensure every file has a DB row
    $ordering = 1;
    foreach ($files as $fname) {
        if (isset($dbMap[$fname])) {
            // exists; maybe update ordering if --reorder
            $id = $dbMap[$fname]['id'];
            if ($reorder && (int)$dbMap[$fname]['ordering'] !== $ordering) {
                updateOrdering($db, $id, $ordering, $dry);
                $totalUpdates++;
            }
        } else {
            insertPhoto($db, $pp, $fname, $ordering, $dry);
            $totalAdds++;
        }
        $ordering++;
    }

    // prune DB rows whose file has gone missing (only when --prune)
    if ($prune) {
        $onDisk = array_flip($files);
        foreach ($dbMap as $fname => $info) {
            if (!isset($onDisk[$fname])) {
                deletePhoto($db, $info['id'], $fname, $dry);
                $totalPruned++;
            }
        }
    }
}

logln("Done. added={$totalAdds} updatedOrdering={$totalUpdates} pruned={$totalPruned}");
exit(0);
