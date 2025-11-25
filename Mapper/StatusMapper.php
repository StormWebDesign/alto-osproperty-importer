<?php
namespace AltoSync\Mapper;

use AltoSync\Logger;

class StatusMapper
{
    /**
     * Maps Alto <web_status> numeric codes to:
     * - OS Property isSold value
     * - Human-friendly Extra Field value
     *
     * Only SSTC and Let Agreed need handling.
     */
    public static function map(?string $webStatus): ?array
    {
        $webStatus = trim((string)$webStatus);

        if ($webStatus === '') {
            return null;
        }

        $n = (int)$webStatus;

        switch ($n) {

            case 3:
                // Sold Subject To Contract
                return [
                    'isSold' => 7,
                    'label'  => 'Sold Subject to Contract'
                ];

            case 104:
                // Let Agreed (as confirmed from feed)
                return [
                    'isSold' => 3,
                    'label'  => 'Let Agreed'
                ];
        }

        // Any other status → leave OS Property to handle it normally
        return null;
    }
}
