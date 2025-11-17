<?php

namespace AltoSync\Mapper;

use AltoSync\Logger;

class PlansMapper
{
    /**
     * Map floorplans (type=2) into pro_pdf_file2–4
     */
    public function map(int $propertyOsId, \SimpleXMLElement $xml, \PDO $db): void
    {
        if (!isset($xml->files->file)) {
            return;
        }

        $slot = 2;

        foreach ($xml->files->file as $f) {

            if ((int)$f['type'] !== 2) {
                continue;
            }

            $url = trim((string)$f->url);
            if (!$url) continue;

            $field = "pro_pdf_file{$slot}";

            $stmt = $db->prepare("
                UPDATE `" . \DB_PREFIX . "osrs_properties`
                SET `$field` = ?
                WHERE id = ?
            ");
            $stmt->execute([$url, $propertyOsId]);

            Logger::log("Saved floorplan URL into {$field} for {$propertyOsId}", "INFO");

            $slot++;
            if ($slot > 4) {
                break;
            }
        }
    }
}
