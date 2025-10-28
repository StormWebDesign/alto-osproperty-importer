<?php
// /public_html/cli/alto-sync/Mapper/AddressMapper.php
// Handles mapping and lookup creation for address-related fields in OS Property.

namespace AltoSync\Mapper;

use AltoSync\Logger;
use PDO;

class AddressMapper
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Map an Alto XML <address> node into a normalised address array
     * ready for OS Property import.
     *
     * @param \SimpleXMLElement $addressXml The <address> node from Alto XML.
     * @param string|null $countryName Optional override for country name (defaults to UK).
     * @return array Returns [
     *     'address'    => string,   // For OS Property "address"
     *     'city_id'    => int,      // FK to #__osrs_cities
     *     'state_id'   => int,      // FK to #__osrs_states
     *     'country_id' => int,      // FK to #__osrs_countries
     *     'postcode'   => string,   // Postcode text
     *     'full'       => string,   // Concatenated full address (optional)
     * ]
     */
    public function mapAddress(\SimpleXMLElement $addressXml, ?string $countryName = 'United Kingdom'): array
    {
        $addressName     = (string)($addressXml->name ?? '');
        $addressStreet   = (string)($addressXml->street ?? '');
        $addressLocality = (string)($addressXml->locality ?? '');
        $town            = (string)($addressXml->town ?? '');
        $county          = (string)($addressXml->county ?? '');
        $postcode        = (string)($addressXml->postcode ?? '');

        // Concise address for OS Property (no postcode)
        $address = trim(implode(', ', array_filter([
            $addressName,
            $addressStreet,
            $addressLocality
        ])));

        // Full concatenated address (for optional usage)
        $fullAddress = trim(implode(', ', array_filter([
            $addressName,
            $addressStreet,
            $addressLocality,
            $town,
            $postcode
        ])));

        // Lookups — reuse existing logic for city, state, and country
        $cityId    = self::getOrCreateLookupId($this->db, 'osrs_cities', 'city', $town);
        $stateId   = self::getOrCreateLookupId($this->db, 'osrs_states', 'state_name', $county);
        $countryId = self::getOrCreateLookupId($this->db, 'osrs_countries', 'country_name', $countryName);

        Logger::log("AddressMapper: Mapped address → city_id={$cityId}, state_id={$stateId}, country_id={$countryId}", 'DEBUG');

        return [
            'address'    => $address,
            'city_id'    => (int)$cityId,
            'state_id'   => (int)$stateId,
            'country_id' => (int)$countryId,
            'postcode'   => $postcode,
            'full'       => $fullAddress,
        ];
    }

    /**
     * Utility: Look up or create lookup IDs in OS Property tables.
     */
    public static function getOrCreateLookupId(PDO $db, string $tableName, string $nameColumn, string $nameValue): int
    {
        $nameValue = trim($nameValue);
        if ($nameValue === '') {
            return 0;
        }

        $prefixedTableName = DB_PREFIX . $tableName;
        try {
            $stmt = $db->prepare("SELECT id FROM `{$prefixedTableName}` WHERE `{$nameColumn}` = ?");
            $stmt->execute([$nameValue]);
            $existingId = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($existingId) {
                return (int)$existingId;
            }

            $stmt = $db->prepare("INSERT INTO `{$prefixedTableName}` (`{$nameColumn}`, published) VALUES (?, 1)");
            $stmt->execute([$nameValue]);
            return (int)$db->lastInsertId();
        } catch (\PDOException $e) {
            Logger::log("AddressMapper DB error for {$tableName} → {$nameValue}: {$e->getMessage()}", 'ERROR');
            return 0;
        }
    }
}
