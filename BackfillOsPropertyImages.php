<?php
// /public_html/cli/alto-sync/BackfillOsPropertyImages.php
// Generate thumb & medium images for existing OS Property photos,
// and (optionally) rebuild missing DB photo rows from originals on disk.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set('UTC');
ini_set('memory_limit', '512M');

function logln(string $msg): void { echo '[' . date('Y-m-d H:i:s') . "] $msg\n"; }
function dieWith(string $msg, int $code = 1): void { logln($msg); exit($code); }

try {
    $db = new PDO(
        'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
        \DB_USER,
        \DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    dieWith('DB connect error: ' . $e->getMessage());
}

/** ---- CLI args ---- */
$pid = null;
$runAll = false;
$dryRun = false;
$rebuildDbFromDisk = false;

foreach ($argv as $arg) {
    if (preg_match('/^--pid=(\d+)$/', $arg, $m)) {
        $pid = (int)$m[1];
    } elseif ($arg === '--all') {
        $runAll = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--rebuild-db-from-disk') {
        $rebuildDbFromDisk = true;
    }
}
if (!$runAll && !$pid) {
    logln("Usage:");
    logln("  php BackfillOsPropertyImages.php --pid=<propertyId> [--dry-run] [--rebuild-db-from-disk]");
    logln("  php BackfillOsPropertyImages.php --all            [--dry-run] [--rebuild-db-from-disk]");
    exit(2);
}

/** ---- Read image config from DB ---- */
$cfgFetch = function(PDO $db, string $key, $default) {
    $stmt = $db->prepare("SELECT fieldvalue FROM `".\DB_PREFIX."osrs_configuration` WHERE fieldname = ?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : $default;
};
$thumbW  = $cfgFetch($db, 'images_thumbnail_width', 170);
$thumbH  = $cfgFetch($db, 'images_thumbnail_height', 110);
$largeW  = $cfgFetch($db, 'images_large_width', 600);
$largeH  = $cfgFetch($db, 'images_large_height', 370);
$quality = max(10, min(100, $cfgFetch($db, 'images_quality', 90)));

$hasImagick = class_exists('Imagick');

logln("Config: thumb {$thumbW}x{$thumbH}, medium {$largeW}x{$largeH}, quality {$quality}");
logln('Imagick: ' . ($hasImagick ? 'yes' : 'no'));
logln('Dry run: ' . ($dryRun ? 'yes' : 'no'));

/** ---- Paths ---- */
$FS_BASE = rtrim((string)(\PROPERTY_IMAGE_UPLOAD_BASE_PATH ?? ''), '/'); // absolute
if ($FS_BASE === '' || !is_dir($FS_BASE)) {
    // Fallback if the constant isn’t defined in this context for some reason.
    $webRootGuess = realpath(__DIR__ . '/../../');
    $FS_BASE = $webRootGuess . '/images/osproperty/properties';
}
if (!is_dir($FS_BASE)) {
    dieWith("ERROR: Image base dir not found: $FS_BASE");
}
logln("FS base: $FS_BASE");

function ensureDir(string $dir, bool $dryRun): bool {
    if (is_dir($dir)) return true;
    if ($dryRun) return true;
    return @mkdir($dir, 0755, true);
}

/** ---- Helpers to write derivatives ---- */
function needsRefresh(string $src, string $dst): bool {
    if (!file_exists($dst)) return true;
    return (filemtime($src) > filemtime($dst));
}

function makeWithImagick(string $src, string $dst, int $w, int $h, int $quality): bool {
    try {
        $im = new Imagick();
        $im->readImage($src);

        // Normalize + orient
        try {
            if ($im->getImageColorspace() !== Imagick::COLORSPACE_RGB) {
                $im->setImageColorspace(Imagick::COLORSPACE_RGB);
            }
        } catch (Throwable $e) {}
        $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        $im->autoOrient();

        // scale to max fit, then center-crop to exact WxH ("cover")
        $im->setImageCompressionQuality($quality);
        $im->thumbnailImage($w, $h, true);
        $geo = $im->getImageGeometry();
        $cropW = min($w, $geo['width']);
        $cropH = min($h, $geo['height']);
        $x = max(0, (int)(($geo['width']  - $cropW) / 2));
        $y = max(0, (int)(($geo['height'] - $cropH) / 2));
        $im->cropImage($cropW, $cropH, $x, $y);

        // choose format based on destination extension
        $ext = strtolower(pathinfo($dst, PATHINFO_EXTENSION));
        $im->setImageFormat(($ext === 'png') ? 'png' : 'jpeg');

        $ok = $im->writeImage($dst);
        $im->destroy();
        return $ok;
    } catch (Throwable $e) {
        logln('    [ERR] Imagick: ' . $e->getMessage());
        return false;
    }
}

function makeWithGd(string $src, string $dst, int $w, int $h, int $quality): bool {
    try {
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $srcImg = match ($ext) {
            'jpg','jpeg' => @imagecreatefromjpeg($src),
            'png'       => @imagecreatefrompng($src),
            'gif'       => @imagecreatefromgif($src),
            default     => null,
        };
        if (!$srcImg) return false;

        $sw = imagesx($srcImg);
        $sh = imagesy($srcImg);
        if ($sw < 1 || $sh < 1) { imagedestroy($srcImg); return false; }

        // cover
        $scale = max($w / $sw, $h / $sh);
        $tw = (int)ceil($sw * $scale);
        $th = (int)ceil($sh * $scale);

        $tmp = imagecreatetruecolor($tw, $th);
        imagecopyresampled($tmp, $srcImg, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        $dstImg = imagecreatetruecolor($w, $h);
        $cx = max(0, (int)(($tw - $w) / 2));
        $cy = max(0, (int)(($th - $h) / 2));
        imagecopy($dstImg, $tmp, 0, 0, $cx, $cy, $w, $h);

        $extOut = strtolower(pathinfo($dst, PATHINFO_EXTENSION));
        $ok = ($extOut === 'png')
            ? imagepng($dstImg, $dst)
            : imagejpeg($dstImg, $dst, max(10, min(100, $quality)));

        imagedestroy($srcImg);
        imagedestroy($tmp);
        imagedestroy($dstImg);
        return $ok;
    } catch (Throwable $e) {
        logln('    [ERR] GD: ' . $e->getMessage());
        return false;
    }
}

/** ---- Optional: insert DB rows when originals exist on disk but DB is empty ---- */
function insertPhotoRow(PDO $db, int $pid, string $fileName, int $ordering, bool $isDefault): bool {
    try {
        $stmt = $db->prepare("
            INSERT INTO `".\DB_PREFIX."osrs_photos` (pro_id, image, image_desc, ordering, is_default)
            VALUES (?, ?, '', ?, ?)
            ON DUPLICATE KEY UPDATE image = VALUES(image), ordering = VALUES(ordering), is_default = VALUES(is_default)
        ");
        return $stmt->execute([$pid, $fileName, $ordering, $isDefault ? 1 : 0]);
    } catch (Throwable $e) {
        logln("    [ERR] DB insert photo row failed (pid={$pid}, img={$fileName}): ".$e->getMessage());
        return false;
    }
}

/** ---- Load photos to process ---- */
$photos = [];
$targetPid = null;

if ($runAll) {
    // All rows in osrs_photos
    $stmt = $db->query("SELECT id, pro_id AS pid, image FROM `".\DB_PREFIX."osrs_photos` ORDER BY pro_id, id");
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    logln('Processing: ALL properties present in osrs_photos');
} else {
    // Rows for a single pid
    $stmt = $db->prepare("SELECT id, pro_id AS pid, image FROM `".\DB_PREFIX."osrs_photos` WHERE pro_id = ? ORDER BY id");
    $stmt->execute([$pid]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $targetPid = $pid;
    logln("Found " . count($photos) . " photo rows (pid={$pid}) in DB.");
}

/** If asked, also rebuild DB rows from disk for properties that have originals but no photos in DB */
if ($rebuildDbFromDisk) {
    $pidsToCheck = [];

    if ($runAll) {
        // scan property directories that exist in filesystem
        foreach (glob($FS_BASE.'/*', GLOB_ONLYDIR) as $dir) {
            $base = basename($dir);
            if (ctype_digit($base)) $pidsToCheck[] = (int)$base;
        }
    } else {
        $pidsToCheck[] = (int)$targetPid;
    }

    foreach ($pidsToCheck as $ppid) {
        // Does DB already have photos for this pid?
        $stmt = $db->prepare("SELECT COUNT(*) FROM `".\DB_PREFIX."osrs_photos` WHERE pro_id = ?");
        $stmt->execute([$ppid]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt > 0) continue; // already has rows

        $propDir = $FS_BASE . '/' . $ppid;
        if (!is_dir($propDir)) continue;

        // find likely originals in property dir root (exclude thumb/medium)
        $candidates = glob($propDir.'/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE) ?: [];
        $candidates = array_values(array_filter($candidates, fn($p) => !is_dir($p) && !preg_match('~/thumb/|/medium/~', $p)));

        if (count($candidates) === 0) continue;

        logln("pid={$ppid}: rebuilding DB photos from disk originals (".count($candidates)." files)...");
        natsort($candidates);
        $ordering = 0;
        foreach ($candidates as $idx => $abs) {
            $file = basename($abs);
            $isDefault = ($idx === 0);
            if ($dryRun) {
                logln("  [dry] would insert DB photo row: {$file} (ordering {$ordering}, default ".($isDefault?'1':'0').")");
            } else {
                insertPhotoRow($db, $ppid, $file, $ordering, $isDefault);
            }
            $ordering++;
        }

        // refresh our $photos list for this pid so derivatives are generated below
        $stmt = $db->prepare("SELECT id, pro_id AS pid, image FROM `".\DB_PREFIX."osrs_photos` WHERE pro_id = ? ORDER BY id");
        $stmt->execute([$ppid]);
        $photos = array_merge($photos, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

/** ---- Backfill derivatives for all (selected) photo rows ---- */
$total = count($photos);
$madeT = $madeM = $missing = $skipped = $errors = 0;

$currentPid = null;

foreach ($photos as $row) {
    $pidRow = (int)$row['pid'];
    $img    = (string)$row['image'];

    if ($targetPid !== null && $pidRow !== $targetPid) {
        // when --pid is used, ignore rows for other pids added by rebuild step
        continue;
    }

    if ($pidRow !== $currentPid) {
        $currentPid = $pidRow;
        logln("Property pid={$pidRow}");
    }

    $propDir   = $FS_BASE . '/' . $pidRow;
    $srcAbs    = $propDir . '/' . $img;
    $thumbDir  = $propDir . '/thumb';
    $medDir    = $propDir . '/medium';
    $thumbAbs  = $thumbDir . '/' . $img;
    $mediumAbs = $medDir  . '/' . $img;

    if (!file_exists($srcAbs)) {
        // tolerant fallback: try same basename with any extension
        $base = preg_replace('/\.[^.]+$/', '', $img);
        $alt  = glob($propDir . '/' . $base . '.*');
        if ($alt && file_exists($alt[0])) {
            $srcAbs = $alt[0];
        } else {
            logln("  [MISSING] original not found: $srcAbs");
            $missing++;
            continue;
        }
    }

    if (!ensureDir($thumbDir, $dryRun) || !ensureDir($medDir, $dryRun)) {
        logln("  [ERR] cannot create derivative dirs for pid={$pidRow}");
        $errors++;
        continue;
    }

    // THUMB
    if (needsRefresh($srcAbs, $thumbAbs)) {
        if ($dryRun) {
            $madeT++; logln("  [dry] thumb  -> " . substr($thumbAbs, -90));
        } else {
            $ok = $hasImagick
                ? makeWithImagick($srcAbs, $thumbAbs, $thumbW, $thumbH, $quality)
                : makeWithGd($srcAbs, $thumbAbs, $thumbW, $thumbH, $quality);
            if ($ok) { $madeT++; logln("  [+] thumb  -> " . substr($thumbAbs, -90)); }
            else     { $errors++; logln("  [ERR] thumb failed for " . substr($srcAbs, -90)); }
        }
    } else {
        $skipped++;
    }

    // MEDIUM
    if (needsRefresh($srcAbs, $mediumAbs)) {
        if ($dryRun) {
            $madeM++; logln("  [dry] medium -> " . substr($mediumAbs, -90));
        } else {
            $ok = $hasImagick
                ? makeWithImagick($srcAbs, $mediumAbs, $largeW, $largeH, $quality)
                : makeWithGd($srcAbs, $mediumAbs, $largeW, $largeH, $quality);
            if ($ok) { $madeM++; logln("  [+] medium -> " . substr($mediumAbs, -90)); }
            else     { $errors++; logln("  [ERR] medium failed for " . substr($srcAbs, -90)); }
        }
    } else {
        $skipped++;
    }
}

logln("Done. photos={$total} madeThumb={$madeT} madeMedium={$madeM} missingSrc={$missing} skipped={$skipped} errors={$errors}");
exit(($errors>0) ? 1 : 0);
