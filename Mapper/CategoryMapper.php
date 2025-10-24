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

        // Defensive logging for diagnostics
        Logger::log("CategoryMapper: Raw market='{$market}', category='{$category}' => normalised='{$m}|{$c}'", 'DEBUG');

        $map = [
            'for sale|residential' => 5,
            'for sale|commercial'  => 7,
            'to let|residential'   => 6,
            'to let|commercial'    => 8,
        ];

        $key = $m . '|' . $c;
        $result = $map[$key] ?? null;

        if ($result === null) {
            Logger::log("CategoryMapper: No mapping found for combination '{$market}' + '{$category}'", 'WARNING');
        } else {
            Logger::log("CategoryMapper: Mapped '{$market}' + '{$category}' to category ID {$result}", 'INFO');
        }

        return $result;
    }

    /**
     * Normalises whitespace, case, and known synonyms.
     */
    private static function normalise(?string $s): string
    {
        if ($s === null) return '';
        $s = trim(preg_replace('/\s+/', ' ', strtolower($s)));

        // Handle common variants
        return match ($s) {
            'sale', 'sales'           => 'for sale',
            'letting', 'lettings',
            'rental', 'rent', 'rentals' => 'to let',
            'res', 'residentials'     => 'residential',
            'comm', 'commercials'     => 'commercial',
            default                   => $s,
        };
    }
}
