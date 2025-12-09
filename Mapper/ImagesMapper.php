<?php
// /public_html/cli/alto-sync/Mapper/ImagesMapper.php
// Handles image collection, download and DB mapping for OS Property (Alto import).

namespace AltoSync\Mapper;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Logger.php';

use AltoSync\Logger;

class ImagesMapper
{
    private static $db = null;
    private static $imageCfg = null;

    /**
     * Init DB just like OsPropertyMapper.
     */
    private static function initDb()
    {
        if (self::$db === null) {
            try {
                self::$db = new \PDO(
                    'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
                    \DB_USER,
                    \DB_PASS,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
                self::$db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                self::$db->setAttribute(\PDO::ATTR_AUTOCOMMIT, true);
            } catch (\PDOException $e) {
                Logger::log("ImagesMapper DB connection failed: " . $e->getMessage(), 'CRITICAL');
                throw new \RuntimeException("ImagesMapper DB connection failed.");
            }
        }
    }

    /**
     * Main entry point – smart sync of images for a property.
     *
     * @param int               $propertyOsId  OS Property ID (pid).
     * @param \SimpleXMLElement $propertyXml   Full Alto property XML.
     * @param string            $altoId        Alto property ID (for logging).
     * @param int               $imagesImportedFlag images_imported from alto_properties (0/1) – optional.
     */
    public static function importImages(
        int $propertyOsId,
        \SimpleXMLElement $propertyXml,
        string $altoId,
        int $imagesImportedFlag = 0
    ): void {
        self::initDb();

        $candidates = self::collectImageCandidates($propertyXml);
        $totalAlto  = count($candidates);

        Logger::log("    ImagesMapper: {$totalAlto} Alto image candidates for Alto ID {$altoId}. images_imported={$imagesImportedFlag}", 'DEBUG');

        if ($totalAlto === 0) {
            Logger::log("    ImagesMapper: No image candidates in Alto XML for Alto ID {$altoId}. Nothing to do.", 'INFO');
            return;
        }

        // Current DB count for this property
        try {
            $stmt = self::$db->prepare("SELECT COUNT(*) FROM `" . \DB_PREFIX . "osrs_photos` WHERE pro_id = ?");
            $stmt->execute([$propertyOsId]);
            $existingCount = (int)$stmt->fetchColumn();
            $stmt->closeCursor();
        } catch (\PDOException $e) {
            Logger::log("    ImagesMapper: Failed to read existing photo count for PID {$propertyOsId}: " . $e->getMessage(), 'ERROR');
            return;
        }

        Logger::log("    ImagesMapper: Existing DB photo count for PID {$propertyOsId} is {$existingCount}.", 'DEBUG');

        // SMART SKIP (Option B):
        //  - If DB already has >= Alto count → assume in sync, skip to avoid duplicates.
        //  - If DB has fewer → import only the *additional* images (by position/order).
        if ($existingCount >= $totalAlto) {
            Logger::log(
                "    ImagesMapper: DB has {$existingCount} photos, Alto has {$totalAlto}. " .
                "Assuming images already imported – skipping new downloads for Alto ID {$altoId}.",
                'INFO'
            );
            return;
        }

        $startIndex    = $existingCount;    // 0-based index into $candidates
        $orderingStart = $existingCount;    // continue ordering from existing count

        Logger::log(
            "    ImagesMapper: Importing images {$startIndex}.. " . ($totalAlto - 1) .
            " for Alto ID {$altoId} (PID {$propertyOsId}).",
            'INFO'
        );

        for ($i = $startIndex; $i < $totalAlto; $i++) {
            $img       = $candidates[$i];
            $isDefault = ($existingCount === 0 && $i === 0);

            $ok = self::downloadAndMapImage(
                $img['url'],
                $propertyOsId,
                $img['name'],
                $orderingStart,
                $img['caption'],
                $isDefault
            );

            if ($ok) {
                $orderingStart++;
            } else {
                Logger::log(
                    "    ImagesMapper: ERROR processing image index {$i} for Alto {$altoId} – URL: {$img['url']}",
                    'ERROR'
                );
            }
        }

        Logger::log(
            "    ImagesMapper: Finished syncing images for Alto ID {$altoId}. " .
            "DB count was {$existingCount}, Alto count {$totalAlto}.",
            'INFO'
        );
    }

    // ---------------------------------------------------------------------
    // Image candidate collection
    // ---------------------------------------------------------------------

    /**
     * Collect image candidates from either <files><file>… or <images><image>…
     * Returns an array of ['url' => string, 'name' => string, 'caption' => string]
     */
    private static function collectImageCandidates(\SimpleXMLElement $p): array
    {
        $out = [];

        // 1) Preferred: <files><file>
        if (isset($p->files) && $p->files->file) {
            foreach ($p->files->file as $fileNode) {
                $typeAttr = isset($fileNode['type']) ? (string)$fileNode['type'] : null;
                $url      = (string)($fileNode->url ?? '');
                $name     = (string)($fileNode->name ?? '');
                $caption  = (string)($fileNode->caption ?? '');

                if ($url !== '' && self::isProbablyImage($url, $name, $typeAttr)) {
                    $out[] = ['url' => $url, 'name' => $name, 'caption' => $caption];
                }
            }
        }

        // 2) Fallback: <images><image>
        if (empty($out) && isset($p->images) && $p->images->image) {
            foreach ($p->images->image as $imageNode) {
                $url = (string)($imageNode->large_url ?? $imageNode->url ?? '');
                $name = (string)($imageNode->name ?? basename((string)$url));
                $caption = (string)($imageNode->caption ?? '');

                if ($url !== '' && self::isProbablyImage($url, $name, null)) {
                    $out[] = ['url' => $url, 'name' => $name, 'caption' => $caption];
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Heuristics & helpers
    // ---------------------------------------------------------------------

    /**
     * Decide whether a file node represents an image we should import.
     */
    private static function isProbablyImage(string $url, string $originalName = '', ?string $typeAttr = null): bool
    {
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $typeAttr = trim((string)$typeAttr);
        $urlPath  = parse_url($url, PHP_URL_PATH) ?: $url;
        $extUrl   = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        $extName  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $hasImgExt = in_array($extUrl, $allowedExt, true) || in_array($extName, $allowedExt, true);
        $typeSuggestsImage = ($typeAttr === '' || $typeAttr === null || in_array($typeAttr, ['0', '1', 'image', 'photo'], true));

        if ($typeSuggestsImage && $hasImgExt) {
            return true;
        }

        if ($typeSuggestsImage) {
            $ct = self::detectImageContentType($url, 8);
            if ($ct !== null) {
                Logger::log("    HEAD says image Content-Type '{$ct}' for URL: {$url}", 'DEBUG');
                return true;
            }
        }

        return false;
    }

    /**
     * Use HEAD request to check if URL is an image; return Content-Type or null.
     */
    private static function detectImageContentType(string $url, int $timeout = 10): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY        => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT       => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT     => 'AltoSync/1.0',
            CURLOPT_FAILONERROR   => false,
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (!$ok || $code >= 400) {
            return null;
        }
        if (is_string($ct) && str_starts_with(strtolower(trim($ct)), 'image/')) {
            return trim(strtolower($ct));
        }
        return null;
    }

    /** Map content-type to a safe file extension (defaults to 'jpg'). */
    private static function detectExtFromContentType(?string $ct): string
    {
        $ct = strtolower((string)$ct);
        return match (true) {
            str_contains($ct, 'image/jpeg'),
            str_contains($ct, 'image/jpg')  => 'jpg',
            str_contains($ct, 'image/png')  => 'png',
            str_contains($ct, 'image/gif')  => 'gif',
            str_contains($ct, 'image/webp') => 'webp',
            default                         => 'jpg',
        };
    }

    private static function ensureImageBaseWritable(): bool
    {
        // PROPERTY_IMAGE_UPLOAD_BASE_PATH should be defined in config.php as:
        // JPATH_ROOT . '/images/osproperty/properties/'
        $base = rtrim(\PROPERTY_IMAGE_UPLOAD_BASE_PATH, '/') . '/';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        if (!is_dir($base)) {
            Logger::log("Image base dir does not exist: {$base}", 'CRITICAL');
            return false;
        }
        if (!is_writable($base)) {
            Logger::log("Image base dir not writable: {$base}", 'CRITICAL');
            return false;
        }
        return true;
    }

    // ---------------------------------------------------------------------
    // Image download + DB mapping
    // ---------------------------------------------------------------------

    private static function downloadAndMapImage(
        string $imageUrl,
        int $propertyOsId,
        string $imageOriginalName,
        int $ordering,
        string $imageDescription = '',
        bool $isDefault = false
    ): bool {
        self::initDb();

        if (!self::ensureImageBaseWritable()) {
            return false;
        }

        $propertyImageDir = \PROPERTY_IMAGE_UPLOAD_BASE_PATH . $propertyOsId . '/';
        Logger::log("DEBUG: ImagesMapper propertyImageDir = " . $propertyImageDir, 'DEBUG');

        if (!is_dir($propertyImageDir)) {
            if (!mkdir($propertyImageDir, 0755, true)) {
                Logger::log("        ERROR: Failed to create property image directory: " . $propertyImageDir, 'ERROR');
                return false;
            }
        }

        // Derive extension (prefer from name/url; if unknown, try HEAD content-type)
        $urlPath     = parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl;
        $extFromUrl  = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        $extFromName = strtolower(pathinfo($imageOriginalName, PATHINFO_EXTENSION));

        $extCandidates = array_filter([$extFromName, $extFromUrl]);
        $ext = '';
        foreach ($extCandidates as $cand) {
            if (in_array($cand, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $ext = $cand;
                break;
            }
        }
        if ($ext === '') {
            $ct = self::detectImageContentType($imageUrl, 8);
            $ext = self::detectExtFromContentType($ct);
        }
        if ($ext === 'jpe') {
            $ext = 'jpg';
        }

        // Stable-ish filename based on property + ordering + sanitized original name
        $sanitizedBase = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $imageOriginalName);
        $sanitizedBase = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $sanitizedBase);

        $base = $propertyOsId . '_' . str_pad((string)$ordering, 3, '0', STR_PAD_LEFT) . '_' . $sanitizedBase;
        $max  = 240 - (strlen($ext) + 1);
        if (strlen($base) > $max) {
            $base = substr($base, 0, $max);
        }
        $safeFileName  = $base . '.' . $ext;

        $localFilePath = $propertyImageDir . $safeFileName;
        $dbImagePath   = $safeFileName;

        // Check if DB row already exists for this property+image
        try {
            $stmtCheck = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_photos` WHERE pro_id = ? AND image = ?");
            $stmtCheck->execute([$propertyOsId, $dbImagePath]);
            $existingPhotoId = $stmtCheck->fetchColumn();
            $stmtCheck->closeCursor();
        } catch (\PDOException $e) {
            Logger::log("        ERROR: Failed to check existing photo row for {$dbImagePath}: " . $e->getMessage(), 'ERROR');
            $existingPhotoId = false;
        }

        // Download if file missing/empty
        if (!file_exists($localFilePath) || filesize($localFilePath) === 0) {
            $fp = @fopen($localFilePath, 'wb');
            if (!$fp) {
                Logger::log("        ERROR: Cannot write to $localFilePath", 'ERROR');
                return false;
            }
            $ch = curl_init($imageUrl);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'AltoSync/1.0',
                CURLOPT_FAILONERROR    => false,
            ]);
            $ok   = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (!$ok || $code >= 400 || !filesize($localFilePath)) {
                @unlink($localFilePath);
                Logger::log("        ERROR: cURL download failed (HTTP $code) for $imageUrl. " . ($err ? "cURL: $err" : ""), 'ERROR');
                return false;
            }

            Logger::log("        Image downloaded and saved to: " . $localFilePath, 'INFO');
        } else {
            Logger::log("        Image already exists locally: " . $localFilePath . ". Skipping download.", 'INFO');
        }

        // Ensure thumb & medium
        self::ensureResizedVariants($propertyOsId, $safeFileName);

        // Insert or update DB row
        try {
            if ($existingPhotoId) {
                $stmt = self::$db->prepare("
                    UPDATE `" . \DB_PREFIX . "osrs_photos`
                    SET image_desc = ?, ordering = ?
                    WHERE id = ?
                ");
                $stmt->execute([$imageDescription, $ordering, $existingPhotoId]);
                Logger::log("        Updated existing photo record (ID: {$existingPhotoId}) for PID {$propertyOsId}.", 'INFO');
            } else {
                $stmt = self::$db->prepare("
                    INSERT INTO `" . \DB_PREFIX . "osrs_photos` (pro_id, image, image_desc, ordering)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$propertyOsId, $dbImagePath, $imageDescription, $ordering]);
                $newId = self::$db->lastInsertId();
                Logger::log("        Inserted new photo record (ID: {$newId}) for PID {$propertyOsId}.", 'INFO');
            }
            unset($stmt);
            return true;
        } catch (\PDOException $e) {
            Logger::log("        Database error mapping image for PID {$propertyOsId}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Image sizing helpers
    // ---------------------------------------------------------------------

    private static function getImageConfig(): array
    {
        if (self::$imageCfg !== null) {
            return self::$imageCfg;
        }

        self::initDb();
        $cfg = [
            'thumb_w' => 170,
            'thumb_h' => 110,
            'large_w' => 600,
            'large_h' => 370,
            'quality' => 90,
        ];

        try {
            $stmt = self::$db->query("
                SELECT fieldname, fieldvalue
                FROM `" . \DB_PREFIX . "osrs_configuration`
                WHERE fieldname IN (
                    'images_thumbnail_width','images_thumbnail_height',
                    'images_large_width','images_large_height','images_quality'
                )
            ");
            $map = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $map[$row['fieldname']] = (int)$row['fieldvalue'];
            }
            $cfg['thumb_w'] = $map['images_thumbnail_width']  ?? $cfg['thumb_w'];
            $cfg['thumb_h'] = $map['images_thumbnail_height'] ?? $cfg['thumb_h'];
            $cfg['large_w'] = $map['images_large_width']      ?? $cfg['large_w'];
            $cfg['large_h'] = $map['images_large_height']     ?? $cfg['large_h'];
            $q = $map['images_quality'] ?? $cfg['quality'];
            $cfg['quality'] = max(1, min(100, (int)$q));
        } catch (\Throwable $e) {
            Logger::log("ImagesMapper: Image config load failed; using defaults. " . $e->getMessage(), 'WARNING');
        }

        self::$imageCfg = $cfg;
        return $cfg;
    }

    private static function ensureResizedVariants(int $propertyOsId, string $fileName): void
    {
        $cfg = self::getImageConfig();

        $propDir   = \PROPERTY_IMAGE_UPLOAD_BASE_PATH . $propertyOsId . '/';
        $src       = $propDir . $fileName;

        if (!is_file($src)) {
            Logger::log("    ImagesMapper: Skipping resize; source not found: {$src}", 'WARNING');
            return;
        }

        $thumbDir  = $propDir . 'thumb/';
        $mediumDir = $propDir . 'medium/';
        if (!is_dir($thumbDir))  @mkdir($thumbDir, 0755, true);
        if (!is_dir($mediumDir)) @mkdir($mediumDir, 0755, true);

        $dstThumb  = $thumbDir  . $fileName;
        $dstMedium = $mediumDir . $fileName;

        $srcMTime = filemtime($src) ?: time();

        if (!file_exists($dstThumb) || (filemtime($dstThumb) ?: 0) < $srcMTime) {
            $ok = self::resizeOne($src, $dstThumb, $cfg['thumb_w'], $cfg['thumb_h'], $cfg['quality']);
            Logger::log(
                $ok ? "        Wrote thumb: {$dstThumb}" : "        FAILED to write thumb: {$dstThumb}",
                $ok ? 'INFO' : 'ERROR'
            );
        }

        if (!file_exists($dstMedium) || (filemtime($dstMedium) ?: 0) < $srcMTime) {
            $ok = self::resizeOne($src, $dstMedium, $cfg['large_w'], $cfg['large_h'], $cfg['quality']);
            Logger::log(
                $ok ? "        Wrote medium: {$dstMedium}" : "        FAILED to write medium: {$dstMedium}",
                $ok ? 'INFO' : 'ERROR'
            );
        }
    }

    private static function resizeOne(string $src, string $dst, int $targetW, int $targetH, int $quality): bool
    {
        try {
            if ($targetW <= 0 || $targetH <= 0) {
                Logger::log("Invalid target size {$targetW}x{$targetH} for {$src}", 'ERROR');
                return false;
            }

            [$w, $h] = @getimagesize($src) ?: [0, 0];
            if ($w <= 0 || $h <= 0) {
                Logger::log("getimagesize failed for {$src}", 'ERROR');
                return false;
            }

            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $im = @imagecreatefromjpeg($src);
                    if (function_exists('exif_read_data')) {
                        $exif = @exif_read_data($src);
                        if ($exif && !empty($exif['Orientation'])) {
                            switch ((int)$exif['Orientation']) {
                                case 3: $im = imagerotate($im, 180, 0); break;
                                case 6: $im = imagerotate($im, -90, 0); break;
                                case 8: $im = imagerotate($im, 90, 0); break;
                            }
                        }
                    }
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
            if (!$im) {
                return false;
            }

            $scale = min($targetW / $w, $targetH / $h, 1.0);
            $newW  = max(1, (int)floor($w * $scale));
            $newH  = max(1, (int)floor($h * $scale));

            $dstIm = imagecreatetruecolor($newW, $newH);
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

            if (!imagecopyresampled($dstIm, $im, 0, 0, 0, 0, $newW, $newH, $w, $h)) {
                imagedestroy($im);
                imagedestroy($dstIm);
                return false;
            }

            @mkdir(dirname($dst), 0755, true);

            $ok = false;
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $ok = imagejpeg($dstIm, $dst, $quality);
                    break;
                case 'png':
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
                @touch($dst, filemtime($src));
            }
            return $ok;
        } catch (\Throwable $e) {
            Logger::log("Resize error for {$src} -> {$dst}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}
