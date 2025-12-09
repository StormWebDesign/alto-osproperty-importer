<?php
namespace AltoSync\Mapper;

use AltoSync\Logger;

class StatusMapper
{
    /**
     * OLD METHOD (kept for backwards compatibility)
     * Maps web_status → isSold integer only.
     */
    public static function mapWebStatusToIsSold($webStatus)
    {
        // Normalise to integer
        $webStatus = (int) $webStatus;

        // Default = Current
        $isSold = 0;

        switch ($webStatus) {

            // Sold STC / Under Offer
            case 7:   // "Sold STC"
            case 3:   // "Let Agreed"
                $isSold = 3;
                break;

            // Fully Sold / Fully Let
            case 1:   // "Sold"
            case 2:   // "Let"
                $isSold = 1;
                break;

            // Everything else = Current
            default:
                $isSold = 0;
                break;
        }

        return $isSold;
    }


    /**
     * NEW METHOD (required by OsPropertyMapper)
     * Returns both:
     *   - isSold (0/1/3)
     *   - readable label ("Sold", "Sold STC", "Let Agreed", etc)
     * Handles both numeric and string Alto <web_status> formats.
     */
    public static function map($webStatus)
    {
        $status = strtolower(trim((string)$webStatus));

        // Default
        $result = [
            'isSold' => 0,
            'label'  => 'Available'
        ];

        // ----------------------
        // NUMERIC STATUS
        // ----------------------
        if (is_numeric($status)) {
            $n = (int)$status;

            switch ($n) {
                case 0:
                    return ['isSold' => 0, 'label' => 'Available'];
                case 1:
                    return ['isSold' => 1, 'label' => 'Sold'];
                case 2:
                    return ['isSold' => 1, 'label' => 'Let'];
                case 3:
                    return ['isSold' => 3, 'label' => 'Let Agreed'];
                case 4:
                    return ['isSold' => 1, 'label' => 'Completed'];
                case 7:
                    return ['isSold' => 3, 'label' => 'Sold STC'];
                case 100:
                    return ['isSold' => 0, 'label' => 'To Let'];
                case 101:
                    return ['isSold' => 3, 'label' => 'Let Agreed'];
                case 102:
                    return ['isSold' => 1, 'label' => 'Let'];
                case 103:
                    return ['isSold' => 1, 'label' => 'Withdrawn'];
                case 104:
                    return ['isSold' => 1, 'label' => 'Completed'];
            }

            return $result;
        }

        // ----------------------
        // STRING STATUS MATCHING
        // ----------------------
        if (str_contains($status, 'sold stc') || str_contains($status, 'sstc')) {
            return ['isSold' => 3, 'label' => 'Sold STC'];
        }

        if (str_contains($status, 'sold')) {
            return ['isSold' => 1, 'label' => 'Sold'];
        }

        if (str_contains($status, 'let agreed')) {
            return ['isSold' => 3, 'label' => 'Let Agreed'];
        }

        if (str_contains($status, 'let')) {
            return ['isSold' => 1, 'label' => 'Let'];
        }

        if (str_contains($status, 'under offer')) {
            return ['isSold' => 3, 'label' => 'Under Offer'];
        }

        return $result;
    }
}
