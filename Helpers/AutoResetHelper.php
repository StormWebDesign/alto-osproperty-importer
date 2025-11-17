<?php

namespace AltoSync\Helpers;

use AltoSync\Logger;

class AutoResetHelper
{
    /**
     * Reset pro_pdf_file1–9 back to NULL for a given OS Property ID.
     */
    public static function resetExtraFilesObject($xml, int $propertyOsId, \PDO $db)
    {
        $fields = [
            'pro_pdf_file1','pro_pdf_file2','pro_pdf_file3',
            'pro_pdf_file4','pro_pdf_file5','pro_pdf_file6',
            'pro_pdf_file7','pro_pdf_file8','pro_pdf_file9',
        ];

        $set = [];
        foreach ($fields as $f) {
            $set[] = "`$f` = NULL";
        }

        $sql = "
            UPDATE `" . \DB_PREFIX . "osrs_properties`
            SET " . implode(', ', $set) . "
            WHERE id = ?
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$propertyOsId]);

        Logger::log("Reset pro_pdf_file1–9 for property {$propertyOsId}", "INFO");
    }
}
