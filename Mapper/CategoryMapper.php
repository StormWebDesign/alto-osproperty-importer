<?php
// /public_html/cli/alto-sync/Mapper/CategoryMapper.php
// Handles mapping of Alto Market + Category to OS Property category IDs.

namespace AltoSync\Mapper;

use AltoSync\Logger;

class CategoryMapper
{
    /**
     * Maps an Alto property’s Market + Category combination
     * to the correct OS Property category ID.
     *
     * OS Property Category IDs:
     *   5 = For Sale - Residential
     *   6 = To Let - Residential
     *   7 = For Sale - Commercial
     *   8 = To Let - Commercial
     *
     * @param string|null $market   e.g. "For Sale", "To Let"
     * @param string|null $category e.g. "Residential", "Commercial"
     * @return int|null The corresponding category ID, or null if no match.
     */
    public static function toOsCategoryId(?string $market, ?string $category): ?int
    {
        $m = self::normalise($market);
        $c = self::normalise($category);

        // Log the raw and normalised inputs
        Logger::log("CategoryMapper: Raw market='{$market}', category='{$category}' => normalised='{$m}|{$c}'", 'DEBUG');

        // Mapping table
        $map = [
            'for sale|residential' => 5,
            'for sale|commercial'  => 7,
            'to let|residential'   => 6,
            'to let|commercial'    => 8,
        ];

        $key = "{$m}|{$c}";

        if (isset($map[$key])) {
            $mappedId = $map[$key];
            Logger::log("CategoryMapper: Mapped '{$market}' + '{$category}' → category_id={$mappedId} (normalised='{$key}')", 'INFO');
            return $mappedId;
        }

        Logger::log("CategoryMapper: No match found for '{$market}' + '{$category}' (normalised='{$key}')", 'WARNING');
        return null;
    }

    /**
     * Normalises whitespace, case, and known synonyms.
     */
    private static function normalise(?string $s): string
    {
        if ($s === null) {
            return '';
        }

        $s = strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));

        // Simplify common market terms
        if (in_array($s, ['sale', 'sales', 'forsale', 'for-sale'])) {
            return 'for sale';
        }
        if (in_array($s, ['let', 'lettings', 'tolet', 'to-let', 'rent', 'rental', 'rentals'])) {
            return 'to let';
        }

        // 🧠 Expanded matching for residential/commercial property types
        if (preg_match('/house|flat|apartment|bungalow|cottage|studio|terraced|semi|detached/i', $s)) {
            return 'residential';
        }
        if (preg_match('/shop|office|industrial|warehouse|land|unit|retail|commercial/i', $s)) {
            return 'commercial';
        }

        // Normalise shorthand variants
        return match ($s) {
            'res', 'residentials' => 'residential',
            'comm', 'commercials' => 'commercial',
            default               => $s,
        };
    }
}
