<?php
/**
 * Generate OS Property "thumb" and "medium" image variants for each property folder.
 * Place this file at: /public_html/cli/alto-sync/ResizeOsPropertyImages.php
 * Run with: php /public_html/cli/alto-sync/ResizeOsPropertyImages.php
 */

declare(strict_types=1);

namespace AltoSync\Tools;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';

use AltoSync\Logger;

class ResizeOsPropertyImages
{
    private \PDO $db;
    private string $baseDir;
    private array $cfg = [
        'thumb_w'  => 170,
        'thumb_h'  => 110,
        'large_w'  => 600,
        'large_h'  => 370,
        'quality'  => 90,
    ];

    public function __construct()
    {
        // DB
        $this->db = new \PDO(
            'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
            \DB_USER,
            \DB_PASS,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        // Root path to property images (no trailing slash)
        $this->baseDir = rtrim($_SERVER['DOCUMENT_ROOT'] . '/images/osproperty/properties', '/');
    }

    public function run(): void
    {
        Logger::log("=== ResizeOsPropertyImages: start ===", 'INFO');
        $this->loadImageConfig();

        if (!is_dir($this->baseDir)) {
            Logger::log("Base dir not found: {$this->baseDir}", 'CRITICAL');
            return;
        }

        // Simple lock to avoid overlapping runs
        $lockFile = __DIR__ . '/.resize_images.lock';
        $lock = @fopen($lockFile, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            Logger::log("Another resize job appears to be running. Exiting.", 'WARNING');
            return;
        }

        try {
            $dh = opendir($this->baseDir);
            if ($dh === false) {
                Logger::log("Cannot open dir: {$this->baseDir}", 'CRITICAL');
                return;
            }

            while (($entry = readdir($dh)) !== false) {
                if ($entry === '.' || $entry === '..') continue;

                $propDir = "{$this->baseDir}/{$entry}";
                if (!is_dir($propDir)) continue;
                if (!ctype_digit($entry)) continue; // property folders are numeric ids

                $this->processPropertyDir((int)$entry, $propDir);
            }
            closedir($dh);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            @unlink($lockFile);
            Logger::log("=== ResizeOsPropertyImages: complete ===", 'INFO');
        }
    }

    private function loadImageConfig(): void
    {
        try {
            $q = $this->db->query("SELECT fieldname, fieldvalue FROM `" . \DB_PREFIX . "osrs_configuration`
                                    WHERE fieldname IN ('images_thumbnail_width','images_thumbnail_height','images_large_width','images_large_height','images_quality')");
            $map = [];
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $map[$row['fieldname']] = (int)$row['fieldvalue'];
            }
            $this->cfg['thumb_w'] = $map['images_thumbnail_width']  ?? $this->cfg['thumb_w'];
            $this->cfg['thumb_h'] = $map['images_thumbnail_height'] ?? $this->cfg['thumb_h'];
            $this->cfg['large_w'] = $map['images_large_width']      ?? $this->cfg['large_w'];
            $this->cfg['large_h'] = $map['images_large_height']     ?? $this->cfg['large_h'];
            $this->cfg['quality'] = max(1, min(100, $map['images_quality'] ?? $this->cfg['quality']));

            Logger::log(sprintf(
                "Using sizes: thumb %dx%d, medium %dx%d, quality %d",
                $this->cfg['thumb_w'], $this->cfg['thumb_h'],
                $this->cfg['large_w'], $this->cfg['large_h'],
                $this->cfg['quality']
            ), 'INFO');
        } catch (\Throwable $e) {
            Logger::log("Failed to read image config; using defaults. Error: " . $e->getMessage(), 'WARNING');
        }
    }

    private function processPropertyDir(int $pid, string $propDir): void
    {
        $thumbDir  = "{$propDir}/thumb";
        $mediumDir = "{$propDir}/medium";

        if (!is_dir($thumbDir))  @mkdir($thumbDir, 0755, true);
        if (!is_dir($mediumDir)) @mkdir($mediumDir, 0755, true);

        $dh = opendir($propDir);
        if ($dh === false) {
            Logger::log("Cannot open property dir: {$propDir}", 'ERROR');
            return;
        }

        $allowed = ['jpg','jpeg','png','gif'];
        while (($file = readdir($dh)) !== false) {
            $srcPath = "{$propDir}/{$file}";
            if (!is_file($srcPath)) continue;
            if (strpos($file, '.') === 0) continue; // dotfiles
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;

            // Skip the subfolders themselves
            if ($file === 'thumb' || $file === 'medium') continue;

            // Destination paths keep the same filename
            $dstThumb  = "{$thumbDir}/{$file}";
            $dstMedium = "{$mediumDir}/{$file}";

            $srcMTime = filemtime($srcPath) ?: time();

            // Thumb
            if (!$this->isFresh($dstThumb, $srcMTime)) {
                $ok = $this->resizeOne($srcPath, $dstThumb, $this->cfg['thumb_w'], $this->cfg['thumb_h'], $this->cfg['quality']);
                if (!$ok) Logger::log("Thumb failed for PID {$pid}: {$file}", 'ERROR');
            }

            // Medium
            if (!$this->isFresh($dstMedium, $srcMTime)) {
                $ok = $this->resizeOne($srcPath, $dstMedium, $this->cfg['large_w'], $this->cfg['large_h'], $this->cfg['quality']);
                if (!$ok) Logger::log("Medium failed for PID {$pid}: {$file}", 'ERROR');
            }
        }
        closedir($dh);
    }

    private function isFresh(string $dst, int $srcMTime): bool
    {
        if (!file_exists($dst)) return false;
        $dstMTime = filemtime($dst) ?: 0;
        return $dstMTime >= $srcMTime;
    }

    /**
     * Resize preserving aspect ratio; do not upscale beyond source.
     * Handles JPG (EXIF orientation), PNG/GIF transparency.
     */
    private function resizeOne(string $src, string $dst, int $targetW, int $targetH, int $quality): bool
    {
        try {
            [$w, $h, $type] = @getimagesize($src);
            if (!$w || !$h) {
                Logger::log("getimagesize failed for {$src}", 'ERROR');
                return false;
            }
            if ($targetW <= 0 || $targetH <= 0) {
                Logger::log("Invalid target size {$targetW}x{$targetH} for {$src}", 'ERROR');
                return false;
            }

            // Load source
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $im = @imagecreatefromjpeg($src);
                    $this->fixExifOrientation($src, $im);
                    break;
                case 'png':
                    $im = @imagecreatefrompng($src);
                    break;
                case 'gif':
                    $im = @imagecreatefromgif($src);
                    break;
                default:
                    return false;
            }
            if (!$im) return false;

            // Compute target while preserving aspect ratio (contain-fit).
            // Avoid upscaling.
            $scale = min($targetW / $w, $targetH / $h, 1.0);
            $newW  = max(1, (int)floor($w * $scale));
            $newH  = max(1, (int)floor($h * $scale));

            // Canvas
            $dstIm = imagecreatetruecolor($newW, $newH);
            if (!$dstIm) {
                imagedestroy($im);
                return false;
            }

            // Transparency for PNG/GIF
            if ($ext === 'png') {
                imagealphablending($dstIm, false);
                imagesavealpha($dstIm, true);
                $transparent = imagecolorallocatealpha($dstIm, 0, 0, 0, 127);
                imagefilledrectangle($dstIm, 0, 0, $newW, $newH, $transparent);
            } elseif ($ext === 'gif') {
                $transIndex = imagecolortransparent($im);
                if ($transIndex >= 0) {
                    $transColor = imagecolorsforindex($im, $transIndex);
                    $transIndexNew = imagecolorallocate($dstIm, $transColor['red'], $transColor['green'], $transColor['blue']);
                    imagefill($dstIm, 0, 0, $transIndexNew);
                    imagecolortransparent($dstIm, $transIndexNew);
                }
            }

            // Resample
            if (!imagecopyresampled($dstIm, $im, 0, 0, 0, 0, $newW, $newH, $w, $h)) {
                imagedestroy($im);
                imagedestroy($dstIm);
                return false;
            }

            // Ensure destination dir exists
            @mkdir(dirname($dst), 0755, true);

            // Save
            $ok = false;
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $ok = imagejpeg($dstIm, $dst, $quality);
                    break;
                case 'png':
                    // Convert 0–100 quality to PNG compression 0–9 (invert scale)
                    $compression = 9 - (int)round(($quality / 100) * 9);
                    $ok = imagepng($dstIm, $dst, $compression);
                    break;
                case 'gif':
                    $ok = imagegif($dstIm, $dst);
                    break;
            }

            imagedestroy($im);
            imagedestroy($dstIm);

            if ($ok) {
                @touch($dst, filemtime($src)); // keep mtime aligned
            }
            return $ok;
        } catch (\Throwable $e) {
            Logger::log("Resize error for {$src} -> {$dst}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    private function fixExifOrientation(string $path, $im): void
    {
        if (!function_exists('exif_read_data')) return;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== 'jpg' && $ext !== 'jpeg') return;

        try {
            $exif = @exif_read_data($path);
            if (!$exif || empty($exif['Orientation'])) return;

            switch ((int)$exif['Orientation']) {
                case 3: $im = imagerotate($im, 180, 0); break;
                case 6: $im = imagerotate($im, -90, 0); break;
                case 8: $im = imagerotate($im, 90, 0); break;
                default: return;
            }
        } catch (\Throwable $e) {
            // ignore EXIF issues
        }
    }
}

(new ResizeOsPropertyImages())->run();
