<?php
// /public_html/cli/alto-sync/Mapper/PropertyTypeMapper.php

namespace AltoSync\Mapper;

use AltoSync\Logger;
use PDO;

/**
 * Maps Alto property type strings to OS Property type_id values.
 * Uses a fixed 1:1 mapping table (recommended for stability).
 */
class PropertyTypeMapper
{
    /** @var PDO */
    protected $db;

    /**
     * Direct Alto → OS Property type_id mapping.
     * Normalized (lowercase) keys MUST match normalized Alto API inputs.
     */
    private array $map = [
        'house'                      => 2,
        'house - detached'           => 6,
        'house - semi-detached'      => 7,
        'house - townhouse'          => 8,
        'house - terraced'           => 9,
        'house - end terrace'        => 10,
        'house - mid terrace'        => 11,

        'flat'                       => 4,
        'block of flats'             => 12,
        'studio'                     => 13,

        'bungalow - detached'        => 5,
        'bungalow - semi-detached'   => 14,

        'barn conversion - unconverted' => 15,
        'barn conversion'               => 16,

        'chapel - converted'         => 17,
        'chapel - unconverted'       => 18,

        'cottage - detached'         => 19,
        'cottage - semi detached'    => 20,
        'cottage - terraced'         => 21,

        'country home'               => 22,
        'holiday chalet'             => 23,
        'small holding 2-50 acres'   => 24,
        'parking space'              => 25,

        'land - building plot'       => 26,
        'land - development site'    => 27,
        'land - small holding'       => 28,
        'land'                       => 3,

        'shop'                       => 29,
        'retail'                     => 30,
        'public house'               => 31,
        'industrial unit'            => 32,
        'guest house'                => 33,
        'factory'                    => 34,
        'office'                     => 35,

        'garage'                     => 36,
        'garage - petrol station'    => 37,
        'garage - lockup'            => 38,

        'business'                   => 39,
        'business as going concern'  => 40,

        'bed and breakfast'          => 41,
        'hotel'                      => 42,
        'care'                       => 43,
        'catering'                   => 44,

        'development property'       => 45,
        'warehouse'                  => 46,

        'restaurant & take away'     => 47,

        'not specified'              => 48,

        // Legacy / fallback
        'other'                      => 1,
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Main mapping method.
     */
    public function map(string $altoType): ?int
    {
        if (empty($altoType)) {
            Logger::log("PropertyTypeMapper: Empty Alto type – defaulting to ID 1 (Other)", 'WARNING');
            return 1;
        }

        $normalized = $this->normalize($altoType);

        // 1. Direct match
        if (isset($this->map[$normalized])) {
            return $this->map[$normalized];
        }

        // 2. Try fuzzy match (helpful for odd spacing or dashes)
        $fuzzy = $this->attemptFuzzyMatch($normalized);
        if ($fuzzy !== null) {
            return $fuzzy;
        }

        // 3. No match found → fallback
        Logger::log("PropertyTypeMapper: Unknown Alto type '{$altoType}', defaulting to ID 1 (Other)", 'WARNING');
        return 1;
    }

    /**
     * Normalize strings for consistent lookups.
     */
    protected function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);
        $value = str_replace(['–', '—'], '-', $value); // Replace em/en dashes with hyphen
        return $value;
    }

    /**
     * Fuzzy matching for close-but-not-exact matches.
     */
    protected function attemptFuzzyMatch(string $search): ?int
    {
        foreach ($this->map as $key => $typeId) {
            similar_text($search, $key, $percent);

            if ($percent >= 85) {
                Logger::log("PropertyTypeMapper: Fuzzy match '{$search}' → '{$key}' (ID {$typeId})", 'DEBUG');
                return $typeId;
            }
        }
        return null;
    }
}
