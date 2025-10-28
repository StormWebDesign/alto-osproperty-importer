<?php
// /public_html/cli/alto-sync/Mapper/CategoryMapper.php

namespace AltoSync\Mapper;

use AltoSync\Logger;

/**
 * CategoryMapper
 * Handles Alto "database" → OS Property category_id mapping.
 *
 * The Alto API v13 <property database="X"> attribute indicates
 * which marketing channel or department the property belongs to.
 *
 * For Tudor Estate Agents, the mapping is:
 *   1 → For Sale - Residential  (OS Property ID 5)
 *   2 → To Let - Residential    (OS Property ID 6)
 *   3 → For Sale - Commercial   (OS Property ID 7)
 *   4 → To Let - Commercial     (OS Property ID 8)
 */
class CategoryMapper
{
    /**
     * Map of Alto "database" values to OS Property category_id.
     * These IDs correspond to rows in #__osrs_categories.
     */
    private static array $databaseMap = [
        '1' => 5, // For Sale - Residential
        '2' => 6, // To Let - Residential
        '3' => 7, // For Sale - Commercial
        '4' => 8, // To Let - Commercial
    ];

    /**
     * Resolve OS Property category_id using the Alto XML <property database=""> attribute.
     *
     * @param string|int|null $databaseAttr The value from <property database="...">
     * @param string|null $market Optional: For Sale / To Let (used only for logging)
     * @param string|null $category Optional: Residential / Commercial (used only for logging)
     * @return int|null The OS Property category ID or null if not found.
     */
    public static function toOsCategoryId(?string $market = null, ?string $category = null, ?string $databaseAttr = null): ?int
    {
        $databaseAttr = trim((string)$databaseAttr);

        // Priority 1: map directly via database attribute
        if ($databaseAttr !== '' && isset(self::$databaseMap[$databaseAttr])) {
            $id = self::$databaseMap[$databaseAttr];
            Logger::log("CategoryMapper: Database='{$databaseAttr}' → OS Category ID {$id} ({$market} {$category})", 'DEBUG');
            return $id;
        }

        // Priority 2: fallback to market/category if database attr missing
        $key = strtolower(trim((string)$market)) . '|' . strtolower(trim((string)$category));
        $fallback = [
            'for sale|residential' => 5,
            'to let|residential'   => 6,
            'for sale|commercial'  => 7,
            'to let|commercial'    => 8,
        ];

        if (isset($fallback[$key])) {
            $id = $fallback[$key];
            Logger::log("CategoryMapper Fallback: '{$market} + {$category}' → OS Category ID {$id}", 'DEBUG');
            return $id;
        }

        Logger::log("CategoryMapper: Unable to map (market='{$market}', category='{$category}', database='{$databaseAttr}')", 'WARNING');
        return null;
    }
}
