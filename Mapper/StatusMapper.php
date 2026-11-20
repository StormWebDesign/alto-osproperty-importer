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
     *   - isSold (0/1/3/5/7)
     *   - readable label ("Sold", "Under Offer", "Let Agreed", etc) — used for logging only
     * Handles both numeric and string Alto <web_status> formats.
     *
     * isSold values MUST match the site's custom OS Property theme
     * (components/com_osproperty/templates/09092025154155default/details.html.tpl.php
     * and listing.html.tpl.php), which labels them by category:
     *
     *   isSold | Lettings (cat 6, 8) | Sales (cat 5, 7)
     *   -------+---------------------+-----------------
     *     0    | (no ribbon)         | (no ribbon)
     *     1    | Let                 | Sold
     *     3    | Let Agreed          | Under Offer
     *     4    | (no ribbon)         | Sold STC
     *     5    | Reserved            | Reserved
     *     7    | Withdrawn           | Withdrawn
     *
     * 2 and 6 render no ribbon. The isSold column is NOT NULL, so active
     * listings must write 0 (not null).
     */
    public static function map($webStatus)
    {
        $status = strtolower(trim((string)$webStatus));

        // Default
        $result = [
            'isSold' => 0,
            'label'  => ''
        ];

        // ----------------------
        // NUMERIC STATUS
        // ----------------------
        // Confirmed from live Alto data for this account: 0, 3, 100, 104.
        // Other codes have never been received; they keep their previously
        // assumed meaning, mapped onto the theme's isSold scale.
        if (is_numeric($status)) {
            $n = (int)$status;

            switch ($n) {
                case 0:
                    // Active for-sale listing — no ribbon
                    return ['isSold' => 0, 'label' => ''];
                case 1:
                    return ['isSold' => 1, 'label' => 'Sold'];
                case 2:
                    return ['isSold' => 1, 'label' => 'Let'];
                case 3:
                    // Alto web_status 3 = SSTC for this account's sales listings.
                    // Client prefers this shown as "Under Offer" (isSold 3 on sales).
                    return ['isSold' => 3, 'label' => 'Under Offer'];
                case 4:
                    return ['isSold' => 1, 'label' => 'Sold'];
                case 7:
                    return ['isSold' => 3, 'label' => 'Under Offer'];
                case 100:
                    // Active to-let listing — no ribbon
                    return ['isSold' => 0, 'label' => ''];
                case 101:
                    return ['isSold' => 3, 'label' => 'Let Agreed'];
                case 102:
                    return ['isSold' => 1, 'label' => 'Let'];
                case 103:
                    return ['isSold' => 7, 'label' => 'Withdrawn'];
                case 104:
                    // Alto web_status 104 = Let Agreed for this account's lettings listings.
                    return ['isSold' => 3, 'label' => 'Let Agreed'];
            }

            Logger::log("  STATUSMAPPER: Unrecognised numeric web_status '{$n}' – defaulting to isSold=0 (no ribbon)", 'WARNING');

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
