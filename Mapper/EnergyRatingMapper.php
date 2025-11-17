<?php

namespace AltoSync\Mapper;

use AltoSync\Logger;

class EnergyRatingMapper
{
    /**
     * Map EPC (type=9) into pro_pdf_file5
     */
    public function map(int $propertyOsId, \SimpleXMLElement $xml, \PDO $db): void
    {
        if (!isset($xml->files->file)) {
            return;
        }

        foreach ($xml->files->file as $f) {

            if ((int)$f['type'] !== 9) {
                continue;
            }

            $url = trim((string)$f->url);
            if (!$url) continue;

            $stmt = $db->prepare("
                UPDATE `" . \DB_PREFIX . "osrs_properties`
                SET pro_pdf_file5 = ?
                WHERE id = ?
            ");
            $stmt->execute([$url, $propertyOsId]);

            Logger::log("Saved EPC URL into pro_pdf_file5 for {$propertyOsId}", "INFO");

            break;
        }
    }
}
