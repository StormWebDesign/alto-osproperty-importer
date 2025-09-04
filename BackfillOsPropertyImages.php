<?php
// /public_html/cli/alto-sync/BackfillOsPropertyImages.php
// Generate thumb & medium images for existing OS Property photos.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set('UTC');

function logln(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

try {
    $db = new PDO(
        'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
        \DB_USER,
        \DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    logln('DB connect error: ' . $e->getMessage());
    exit(1);
}

/** CLI args */
$pid     = null;
$runAll  = false;
foreach ($argv as $arg) {
    if (preg_match('/^--pid=(\d+)$/', $arg, $m)) {
        $pid = (int)$m[1];
    } elseif ($arg === '--all') {
        $runAll = true;
    }
}
if (!$runAll && !$pid) {
    logln("Usage: php BackfillOsPropertyImages.php --pid=<propertyId>  OR  --all");
    exit(2);
}

/** Read image config from DB */
function cfg(PDO $db, string $key, $default) {
    $stmt = $db->prepare("SELECT fieldvalue FROM `" . \DB_PREFIX . "osrs_configuration` WHERE fieldname = ?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v !== false ? $v : $default;
}
$thumbW  = (int)cfg($db, 'images_thumbnail_width', 170);
$thumbH  = (int)cfg($db, 'images_thumbnail_height', 110);
$largeW  = (int)cfg($db, 'images_large_width', 600);
$largeH  = (int)cfg($db, 'images_large_height', 370);
$quality = (int)cfg($db, 'images_quality', 90);
logln("Config: thumb {$thumbW}x{$thumbH}, medium {$largeW}x{$largeH}, quality {$quality}");

$hasImagick = class_exists('Imagick');
logln('Imagick: ' . ($hasImagick ? 'yes' : 'no'));

/** Paths */
$FS_BASE = rtrim(\PROPERTY_IMAGE_UPLOAD_BASE_PATH ?? '', '/'); // e.g. /home/.../public_html/images/osproperty/properties
if ($FS_BASE === '' || !is_dir($FS_BASE)) {
    // Fallback if constant isn’t present for some reason.
    // Backfill lives in /public_html/cli/alto-sync — web root is two dirs up.
    $webRootGuess = realpath(__DIR__ . '/../../');
    $FS_BASE = $webRootGuess . '/images/osproperty/properties';
}
if (!is_dir($FS_BASE)) {
    logln("ERROR: Image base dir not found: $FS_BASE");
    exit(1);
}

/** Load photos to process */
if ($runAll) {
    $stmt = $db->query("SELECT id, pro_id AS pid, image FROM `" . \DB_PREFIX . "osrs_photos` ORDER BY pro_id, id");
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $targetPid = null;
    logln('Processing: ALL properties in osrs_photos');
} else {
    $stmt = $db->prepare("SELECT id, pro_id AS pid, image FROM `" . \DB_PREFIX . "osrs_photos` WHERE pro_id = ? ORDER BY id");
    $stmt->execute([$pid]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $targetPid = $pid;
    logln("Found " . count($photos) . " photo rows (pid={$pid}).");
}

$total = count($photos);
$madeT = $madeM = $missing = $skipped = $errors = 0;

function ensureDir(string $dir): bool {
    return is_dir($dir) || mkdir($dir, 0755, true);
}

function makeWithImagick(string $src, string $dst, int $w, int $h, int $quality): bool {
    try {
        $im = new Imagick();
        $im->readImage($src);

        // Normalize colorspace to sRGB (some JPEGs are CMYK)
        try {
            if ($im->getImageColorspace() !== Imagick::COLORSPACE_RGB) {
                $im->setImageColorspace(Imagick::COLORSPACE_RGB);
            }
        } catch (Throwable $e) {}

        $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT); // avoid rotated thumbs
        $im->autoOrient();

        // Create a cover-crop to requested WxH (same behaviour users expect in frontend lists)
        $im->setImageCompressionQuality($quality);
        $im->thumbnailImage($w, $h, true); // preserve aspect ratio, max fit
        // If we need strict WxH, do a center crop
        $geo = $im->getImageGeometry();
        if ($geo['width'] > $w || $geo['height'] > $h) {
            $x = max(0, (int)(($geo['width'] - $w) / 2));
            $y = max(0, (int)(($geo['height'] - $h) / 2));
            $im->cropImage(min($w, $geo['width']), min($h, $geo['height']), $x, $y);
        }

        // pick format from extension
        $ext = strtolower(pathinfo($dst, PATHINFO_EXTENSION));
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $im->setImageFormat('jpeg');
        } elseif ($ext === 'png') {
            $im->setImageFormat('png');
        }

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
            'jpg','jpeg' => imagecreatefromjpeg($src),
            'png'       => imagecreatefrompng($src),
            'gif'       => imagecreatefromgif($src),
            default     => null,
        };
        if (!$srcImg) return false;

        $sw = imagesx($srcImg);
        $sh = imagesy($srcImg);
        if ($sw < 1 || $sh < 1) { imagedestroy($srcImg); return false; }

        // scale to cover WxH, then crop center
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
        $ok = false;
        if ($extOut === 'png') {
            $ok = imagepng($dstImg, $dst);
        } else { // jpeg default
            $ok = imagejpeg($dstImg, $dst, max(10, min(100, $quality)));
        }

        imagedestroy($srcImg);
        imagedestroy($tmp);
        imagedestroy($dstImg);
        return $ok;
    } catch (Throwable $e) {
        logln('    [ERR] GD: ' . $e->getMessage());
        return false;
    }
}

function needsRefresh(string $src, string $dst): bool {
    if (!file_exists($dst)) return true;
    return (filemtime($src) > filemtime($dst));
}

$currentPid = null;
foreach ($photos as $row) {
    $pid  = (int)$row['pid'];
    $img  = (string)$row['image'];

    // Group logging by property
    if ($pid !== $currentPid) {
        $currentPid = $pid;
        logln("Property pid={$pid}");
    }

    // Build absolute filesystem paths
    $propDir   = $FS_BASE . '/' . $pid;
    $srcAbs    = $propDir . '/' . $img;
    $thumbDir  = $propDir . '/thumb';
    $medDir    = $propDir . '/medium';
    $thumbAbs  = $thumbDir . '/' . $img;
    $mediumAbs = $medDir  . '/' . $img;

    if (!file_exists($srcAbs)) {
        // If not found, try a tolerant fallback: sometimes double extensions happen; try without the last extension.
        $base = preg_replace('/\.[^.]+$/', '', $img);
        $alt  = glob($propDir . '/' . $base . '.*');
        if ($alt && file_exists($alt[0])) {
            $srcAbs   = $alt[0];
            // keep the same output filename (what frontend expects), even if source differs
        } else {
            logln("  [MISSING] src not found: $srcAbs");
            $missing++;
            continue;
        }
    }

    // Ensure dirs
    if (!ensureDir($thumbDir) || !ensureDir($medDir)) {
        logln("  [ERR] cannot create derivative dirs for pid={$pid}");
        $errors++;
        continue;
    }

    // THUMB
    if (needsRefresh($srcAbs, $thumbAbs)) {
        $ok = $hasImagick
            ? makeWithImagick($srcAbs, $thumbAbs, $thumbW, $thumbH, $quality)
            : makeWithGd($srcAbs, $thumbAbs, $thumbW, $thumbH, $quality);
        if ($ok) { $madeT++; logln("  [+] thumb  -> " . substr($thumbAbs, -80)); }
        else     { $errors++; logln("  [ERR] thumb failed for " . substr($srcAbs, -80)); }
    } else {
        $skipped++;
    }

    // MEDIUM
    if (needsRefresh($srcAbs, $mediumAbs)) {
        $ok = $hasImagick
            ? makeWithImagick($srcAbs, $mediumAbs, $largeW, $largeH, $quality)
            : makeWithGd($srcAbs, $mediumAbs, $largeW, $largeH, $quality);
        if ($ok) { $madeM++; logln("  [+] medium -> " . substr($mediumAbs, -80)); }
        else     { $errors++; logln("  [ERR] medium failed for " . substr($srcAbs, -80)); }
    } else {
        $skipped++;
    }
}

logln("Done. photos={$total} madeThumb={$madeT} madeMedium={$madeM} missingSrc={$missing} skipped={$skipped} errors={$errors}");
exit( ($errors>0) ? 1 : 0 );
