<?php
// /public_html/cli/alto-sync/Mapper/OsPropertyMapper.php
// This file was originally xml_to_sql_mapper.php and has been moved/renamed.

namespace AltoSync\Mapper;

// Include the configuration file for database credentials and other settings
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Logger.php'; // Ensure Logger is available here

use AltoSync\Logger; // Use the Logger class

/**
 * Class OsPropertyMapper
 * Maps Alto XML data to the appropriate database tables for OS Property.
 */
class OsPropertyMapper {

    private static $db = null;

    /**
     * Initializes the database connection.
     */
    private static function initDb() {
        if (self::$db === null) {
            try {
                self::$db = new \PDO(
                    'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
                    \DB_USER,
                    \DB_PASS,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
                // Ensure buffered queries for robustness against pending result sets
                self::$db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); 
            } catch (\PDOException $e) {
                Logger::log("Database connection failed in OsPropertyMapper: " . $e->getMessage(), 'CRITICAL');
                // Use RuntimeException to allow caller to catch this critical error
                throw new \RuntimeException("Database connection failed in mapper.");
            }
        }
    }

    /**
     * Converts a string to a URL-safe slug.
     * Replaces JFilterOutput::stringURLSafe for Joomla 4/5 compatibility in CLI.
     * @param string $string The input string.
     * @return string The URL-safe slug.
     */
    private static function createSlug($string) {
        $string = strtolower(trim($string));
        // Replace non-alphanumeric characters (except hyphen) with hyphen
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        // Replace multiple hyphens with a single hyphen
        $string = preg_replace('/-+/', '-', $string);
        // Remove leading/trailing hyphens
        $string = trim($string, '-');
        return $string;
    }

    /**
     * Helper function to get or create lookup IDs in common tables (cities, countries, etc.).
     * @param \PDO $db The PDO database connection.
     * @param string $tableName The table name (e.g., 'osrs_cities').
     * @param string $nameColumn The column name for the value (e.g., 'city').
     * @param string $nameValue The value to lookup/insert (e.g., 'London').
     * @return int|false The ID of the existing or newly created record, or false on error/empty value.
     */
    public static function getOrCreateLookupId(\PDO $db, $tableName, $nameColumn, $nameValue) {
        $nameValue = \trim($nameValue);
        if ($nameValue === '') {
            Logger::log("Attempted to lookup/create " . $tableName . " with empty value for " . $nameColumn, 'WARNING');
            // For cities/states/countries, we can return 0 if empty/invalid to allow the property/company to be inserted.
            // OS Property might then show a generic "Unknown" or nothing for that field.
            return 0; 
        }

        $prefixedTableName = \DB_PREFIX . $tableName;
        $nameColumn = \preg_replace('/[^a-zA-Z0-9_]/', '', $nameColumn); // Basic sanitization

        try {
            $stmt = $db->prepare("SELECT id FROM `" . $prefixedTableName . "` WHERE `" . $nameColumn . "` = ?");
            $stmt->execute([$nameValue]);
            $existingId = $stmt->fetchColumn();
            $stmt->closeCursor(); // Always close cursor
            unset($stmt); // Explicitly unset

            if ($existingId) {
                return (int)$existingId;
            } else {
                $stmt = $db->prepare("INSERT INTO `" . $prefixedTableName . "` (`" . $nameColumn . "`, published) VALUES (?, 1)");
                $stmt->execute([$nameValue]);
                $newId = $db->lastInsertId();
                unset($stmt); // Explicitly unset
                return (int)$newId;
            }
        } catch (\PDOException $e) {
            Logger::log("Database error in getOrCreateLookupId for " . $prefixedTableName . " (" . $nameValue . "): " . $e->getMessage(), 'ERROR');
            return false;
        }
    }


    /**
     * Gets or creates a Joomla user for the negotiator and returns their user ID.
     * @param \PDO $db The PDO database connection.
     * @param string $negotiatorId The Alto negotiator ID.
     * @param string $negotiatorName The negotiator's full name.
     * @param string $negotiatorEmail The negotiator's email address.
     * @param string $negotiatorPhone The negotiator's phone number.
     * @return int|false The Joomla user ID, or false on error/empty data.
     */
    public static function getOrCreateAgentId(\PDO $db, $negotiatorId, $negotiatorName, $negotiatorEmail, $negotiatorPhone) {
        $negotiatorEmail = \trim($negotiatorEmail); // Trim email first

        if ($negotiatorEmail === '' || !\filter_var($negotiatorEmail, FILTER_VALIDATE_EMAIL)) {
            Logger::log("Cannot create agent: Invalid or empty email provided for negotiator ID " . $negotiatorId, 'WARNING');
            return false;
        }

        // Check if an agent already exists by Alto ID (custom column in Joomla users table, 'alto_negotiator_id')
        $stmt = $db->prepare("SELECT id FROM `" . \DB_PREFIX . "users` WHERE `alto_negotiator_id` = ?");
        $stmt->execute([$negotiatorId]);
        $existingJoomlaUserId = $stmt->fetchColumn();
        $stmt->closeCursor(); // Always close cursor
        unset($stmt); // Explicitly unset

        if ($existingJoomlaUserId) {
            $stmt = $db->prepare("
                UPDATE `" . \DB_PREFIX . "users` SET
                    `name` = ?, `email` = ?, `modified` = NOW()
                WHERE `id` = ?
            ");
            $stmt->execute([$negotiatorName, $negotiatorEmail, $existingJoomlaUserId]);
            unset($stmt); // Explicitly unset
            Logger::log("    Updated existing agent (Joomla ID: " . $existingJoomlaUserId . ") for Alto Negotiator ID: " . $negotiatorId, 'INFO');
            return (int)$existingJoomlaUserId;
        } else {
            $username = \strtolower(\str_replace(' ', '', $negotiatorName)) . '_' . $negotiatorId;
            $username = \substr(\preg_replace('/[^a-z0-9]/', '', $username), 0, 150);
            $username = $username . '_' . \uniqid(); // Ensure uniqueness

            $password = \password_hash(\substr(\md5(\rand()), 0, 8), PASSWORD_DEFAULT);

            try {
                $stmt = $db->prepare("
                    INSERT INTO `" . \DB_PREFIX . "users` (
                        `name`, `username`, `email`, `password`, `registerDate`, `lastvisitDate`, `activation`,
                        `sendEmail`, `block`, `requireReset`, `alto_negotiator_id`
                    ) VALUES (
                        ?, ?, ?, ?, NOW(), '0000-00-00 00:00:00', '',
                        0, 0, 0, ?
                    )
                ");
                $stmt->execute([$negotiatorName, $username, $negotiatorEmail, $password, $negotiatorId]);
                $newJoomlaUserId = $db->lastInsertId();
                unset($stmt); // Explicitly unset

                if ($newJoomlaUserId) {
                    Logger::log("    Created new Joomla user (ID: " . $newJoomlaUserId . ") for Alto Negotiator ID: " . $negotiatorId, 'INFO');

                    $groupStmt = $db->prepare("INSERT IGNORE INTO `" . \DB_PREFIX . "user_usergroup_map` (user_id, group_id) VALUES (?, ?)");
                    $groupStmt->execute([$newJoomlaUserId, 2]);
                    unset($groupStmt); // Explicitly unset

                    return (int)$newJoomlaUserId;
                }
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) { // Integrity constraint violation (e.g., duplicate unique key)
                    Logger::log("    Attempted to create duplicate user for negotiator " . $negotiatorId . " (email: " . $negotiatorEmail . "): " . $e->getMessage(), 'WARNING');
                    $stmt = $db->prepare("SELECT id FROM `" . \DB_PREFIX . "users` WHERE `username` = ? OR `email` = ?");
                    $stmt->execute([$username, $negotiatorEmail]);
                    $existingId = $stmt->fetchColumn();
                    $stmt->closeCursor(); // Always close cursor
                    unset($stmt); // Explicitly unset
                    if ($existingId) {
                        Logger::log("    Found existing Joomla user ID " . $existingId . " for duplicate negotiator " . $negotiatorId, 'INFO');
                        return (int)$existingId;
                    }
                }
                Logger::log("Database error creating agent for Alto Negotiator ID " . $negotiatorId . ": " . $e->getMessage(), 'CRITICAL');
                return false;
            }
        }
        return false;
    }


    /**
     * Maps branch details from XML to the `#__osrs_companies` table.
     * @param \SimpleXMLElement $branchXmlObject A SimpleXMLElement object representing a single <branch> node.
     * @return bool True on success, false on failure.
     */
    public static function mapBranchDetailsToDatabase(\SimpleXMLElement $branchXmlObject) {
        self::initDb();

        try {
            $branchid = (string)$branchXmlObject->branchid;
            // Provide sensible defaults for potentially empty XML nodes
            $branchName = (string)$branchXmlObject->name ?: 'Branch ' . $branchid; 
            $branchUrl = (string)$branchXmlObject->url;

            $addressLine1 = (string)$branchXmlObject->address->line1;
            $addressLine2 = (string)$branchXmlObject->address->line2;
            $addressLine3 = (string)$branchXmlObject->address->line3;
            $town = (string)$branchXmlObject->address->town;
            $postcode = (string)$branchXmlObject->address->postcode;
            $country = (string)$branchXmlObject->address->country ?: 'United Kingdom'; // Default country

            $email = (string)$branchXmlObject->email;
            $telephone = (string)$branchXmlObject->telephone; 
            $fax = (string)$branchXmlObject->fax;             
            $website = (string)$branchXmlObject->website;     

            // Pass 0 if town/country is empty, as per getOrCreateLookupId returning 0 for empty values
            $cityId = self::getOrCreateLookupId(self::$db, 'osrs_cities', 'city', $town);
            $countryId = self::getOrCreateLookupId(self::$db, 'osrs_countries', 'country_name', $country);
            
            // OS Property's `address` field in `osrs_companies` typically stores a single string for address.
            // Concatenate address lines for this field.
            $fullAddress = \trim(\implode(', ', \array_filter([$addressLine1, $addressLine2, $addressLine3])));


            $stmt = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_companies` WHERE alto_branch_id = ?");
            $stmt->execute([$branchid]);
            $existingOsCompanyId = $stmt->fetchColumn();
            $stmt->closeCursor(); // Always close cursor
            unset($stmt); // Explicitly unset

            if ($existingOsCompanyId) {
                Logger::log("    Updating existing Branch (Company) ID " . $existingOsCompanyId . " for Alto Branch ID: " . $branchid . ".", 'INFO');
                $stmt = self::$db->prepare("
                    UPDATE `" . \DB_PREFIX . "osrs_companies` SET
                        `company_name` = ?,
                        `company_alias` = ?,
                        `email` = ?,
                        `phone` = ?,
                        `fax` = ?,
                        `address` = ?,
                        `city` = ?,
                        `country` = ?,
                        `website` = ?,
                        `postcode` = ?, 
                        `published` = 1
                    WHERE `id` = ?
                ");
                $success = $stmt->execute([
                    $branchName,
                    self::createSlug($branchName), 
                    $email,
                    $telephone,
                    $fax,
                    $fullAddress, 
                    $cityId,
                    $countryId,
                    $website,
                    $postcode, 
                    $existingOsCompanyId
                ]);
                unset($stmt); 
                Logger::log("    Branch (Company) ID " . $branchid . " updated in " . \DB_PREFIX . "osrs_companies (OS Company ID: " . $existingOsCompanyId . ").", 'INFO');
            } else {
                Logger::log("    Inserting new Branch (Company) for Alto Branch ID: " . $branchid . ".", 'INFO');
                $stmt = self::$db->prepare("
                    INSERT INTO `" . \DB_PREFIX . "osrs_companies` (
                        `alto_branch_id`, `company_name`, `company_alias`, `email`, `phone`, `fax`,
                        `address`, `city`, `country`, `website`, `postcode`, `published`, `user_id` 
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, 1, 0 
                    )
                ");
                $success = $stmt->execute([
                    $branchid,
                    $branchName,
                    self::createSlug($branchName), 
                    $email,
                    $telephone,
                    $fax,
                    $fullAddress, 
                    $cityId,
                    $countryId,
                    $website,
                    $postcode 
                ]);
                $newOsCompanyId = self::$db->lastInsertId();
                unset($stmt); 
                Logger::log("    New Branch (Company) ID " . $branchid . " inserted into " . \DB_PREFIX . "osrs_companies (OS Company ID: " . $newOsCompanyId . ").", 'INFO');
            }

            return $success;

        } catch (\PDOException $e) {
            Logger::log("Database error mapping branch to osrs_companies: " . $e->getMessage(), 'ERROR');
            return false;
        } catch (\Exception $e) {
            Logger::log("General error mapping branch to osrs_companies: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }


    /**
     * Maps property details from XML (SimpleXMLElement object) to database tables.
     * @param \SimpleXMLElement $propertyXmlObject A SimpleXMLElement object for a single property.
     * @param string $altoBranchId The Alto branch ID this property belongs to (from alto_properties table).
     * @return bool True on success, false on failure.
     */
    public static function mapPropertyDetailsToDatabase(\SimpleXMLElement $propertyXmlObject, $altoBranchId) {
        self::initDb();
        Logger::log("  Starting mapping for property XML.", 'INFO');

        try {
            $altoId = (string)$propertyXmlObject->attributes()->id; 
            if (\trim($altoId) === '' && isset($propertyXmlObject->prop_id)) {
                $altoId = (string)$propertyXmlObject->prop_id;
            }
            if (\trim($altoId) === '') {
                Logger::log("  ERROR: Alto Property ID not found in the full property XML object. Cannot map property.", 'ERROR');
                return false;
            }

            Logger::log("  Processing Alto Property ID: " . $altoId, 'INFO');

            // Default empty/invalid values to avoid errors
            $webStatus = (string)$propertyXmlObject->web_status ?: 'available'; 
            $propertyType = (string)$propertyXmlObject->property_type ?: 'Unknown';
            $bedrooms = (int)$propertyXmlObject->bedrooms;
            // Bathrooms are decimal(4,2) in DB, cast to float from Alto's int
            $bathrooms = (float)$propertyXmlObject->bathrooms; 
            $receptions = (int)$propertyXmlObject->receptions;

            // Address components for OS Property `#__osrs_properties` table
            $addressName = (string)$propertyXmlObject->address->name;
            $addressStreet = (string)$propertyXmlObject->address->street;
            $addressLocality = (string)$propertyXmlObject->address->locality; 
            $town = (string)$propertyXmlObject->address->town;
            $county = (string)$propertyXmlObject->address->county;
            $postcode = (string)$propertyXmlObject->address->postcode;
            $country = (string)$propertyXmlObject->country ?: 'United Kingdom'; 
            $displayAddress = (string)$propertyXmlObject->displayaddress ?: 'Property ' . $altoId; 

            $priceValue = (float)$propertyXmlObject->price->value;
            $priceQualifier = (string)$propertyXmlObject->price->qualifier;
            $displayText = (string)$propertyXmlObject->price->display_text;

            $summary = (string)$propertyXmlObject->summary;
            $description = (string)$propertyXmlObject->description;
            // Dates are DATE type, so format as Y-m-d
            $dateAdded = (string)$propertyXmlObject->date_added;
            $dateLastModified = (string)$propertyXmlObject->date_last_modified;

            $tenure = (string)$propertyXmlObject->tenure;
            $yearBuilt = (int)$propertyXmlObject->year_built; 

            $epcCurrentEnergyEfficiency = (string)$propertyXmlObject->epc->current_energy_efficiency;
            $epcCurrentEnvironmentalImpact = (string)$propertyXmlObject->epc->current_environmental_impact;

            $totalFloorAreaSqft = (float)$propertyXmlObject->floor_area->total_floor_area_sqft;
            $totalLandAreaSqft = (float)$propertyXmlObject->land_area->total_land_area_sqft;

            $negotiatorId = (string)$propertyXmlObject->negotiator->id;
            $negotiatorName = (string)$propertyXmlObject->negotiator->name;
            $negotiatorEmail = (string)$propertyXmlObject->negotiator->email;
            $negotiatorPhone = (string)$propertyXmlObject->negotiator->phone;

            $latitude = (string)$propertyXmlObject->latitude; 
            $longitude = (string)$propertyXmlObject->longitude; 

            // Lookup IDs for OS Property specific tables
            $cityId = self::getOrCreateLookupId(self::$db, 'osrs_cities', 'city', $town);
            $stateId = self::getOrCreateLookupId(self::$db, 'osrs_states', 'state_name', $county);
            $countryId = self::getOrCreateLookupId(self::$db, 'osrs_countries', 'country_name', $country);
            $propertyTypeId = self::getOrCreateLookupId(self::$db, 'osrs_types', 'type_name', $propertyType);
            $agentId = self::getOrCreateAgentId(self::$db, $negotiatorId, $negotiatorName, $negotiatorEmail, $negotiatorPhone);

            // Determine OS Property status based on Alto web_status
            $osPropertyStatus = 1; // Default to Published (1)
            switch (strtolower($webStatus)) {
                case 'for sale':
                case 'to let':
                case 'available':
                    $osPropertyStatus = 1; // Published
                    break;
                case 'sold':
                case 'let':
                case 'under offer':
                case 'sstc':
                case 'stc':
                case 'withdrawn':
                case 'potential':
                    $osPropertyStatus = 0; // Unpublished/Archived in OS Property
                    break;
                default:
                    $osPropertyStatus = 1; // Default to published if unknown status
                    Logger::log("  Unknown web_status for property " . $altoId . ": " . $webStatus . ". Defaulting to published.", 'WARNING');
                    break;
            }

            // Get Joomla company (branch) ID from alto_branch_id
            $stmt = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_companies` WHERE alto_branch_id = ?");
            $stmt->execute([$altoBranchId]);
            $companyJoomlaId = $stmt->fetchColumn();
            $stmt->closeCursor(); 
            unset($stmt); 
            if (!$companyJoomlaId) {
                Logger::log("  Could not find OS Property Company ID for Alto branch ID: " . $altoBranchId . ". Property " . $altoId . " will not be associated with a company. Assigning to default company 0.", 'WARNING');
                $companyJoomlaId = 0; // Set to 0 or a default if no matching branch/company found
            }
            
            // Prepare dates for MySQL (convert YYYY-MM-DDTHH:MM:SS.ms to YYYY-MM-DD) as 'created' and 'modified' are DATE type
            $createdDate = ($dateAdded ? \date('Y-m-d', \strtotime($dateAdded)) : \date('Y-m-d'));
            $modifiedDate = ($dateLastModified ? \date('Y-m-d', \strtotime($dateLastModified)) : \date('Y-m-d'));

            $agentUserId = $agentId;
            $currencyId = 1; // Assuming 1 is the ID for the default currency (e.g., GBP) in OS Property's #__osrs_currencies table

            // Check if property exists in OS Property by alto_id (our custom column)
            $stmt = self::$db->prepare("SELECT id FROM `" . \DB_PREFIX . "osrs_properties` WHERE alto_id = ?");
            $stmt->execute([$altoId]);
            $existingOsPropertyId = $stmt->fetchColumn();
            $stmt->closeCursor(); 
            unset($stmt); 

            // The `ref` column is for the property reference. This is where Alto's prop_id (altoId) should go.
            $propertyRef = (string)$altoId; 

            // Concatenate address parts for the `address` field in `osrs_properties`
            $concatAddress = \trim(\implode(', ', \array_filter([$addressName, $addressStreet, $addressLocality])));

            if ($existingOsPropertyId) {
                Logger::log("  Updating existing OS Property ID " . $existingOsPropertyId . " for Alto ID: " . $altoId, 'INFO');
                $stmt = self::$db->prepare("
                    UPDATE `" . \DB_PREFIX . "osrs_properties` SET
                        `pro_name` = ?,          
                        `pro_type` = ?,          
                        `pro_alias` = ?,         
                        `address` = ?,          
                        `country` = ?,           
                        `state` = ?,             
                        `city` = ?,              
                        `postcode` = ?,
                        `pro_small_desc` = ?,    
                        `pro_full_desc` = ?,     
                        `price` = ?,
                        `price_text` = ?,       
                        `curr` = ?,             
                        `bed_room` = ?,         
                        `bath_room` = ?,        
                        `rooms` = ?,            
                        `lat_add` = ?,           
                        `long_add` = ?,          
                        `ref` = ?,              
                        `agent_id` = ?,          
                        `published` = ?,
                        `hits` = `hits`,         
                        `approved` = 1,          
                        `access` = 1,            
                        `modified` = ?,         
                        `company_id` = ?,         
                        `square_feet` = ?,      
                        `lot_size` = ?,         
                        `built_on` = ?          
                    WHERE `id` = ?
                ");
                $success = $stmt->execute([
                    $displayAddress, $propertyTypeId, self::createSlug($displayAddress), 
                    $concatAddress, 
                    $countryId, $stateId, $cityId, $postcode, 
                    $summary, $description, 
                    $priceValue, $displayText, $currencyId, 
                    $bedrooms, $bathrooms, $receptions, 
                    $latitude, $longitude, 
                    $propertyRef, 
                    $agentUserId, $osPropertyStatus, 
                    $modifiedDate, 
                    $companyJoomlaId, 
                    $totalFloorAreaSqft, $totalLandAreaSqft, $yearBuilt,
                    $existingOsPropertyId 
                ]);
                unset($stmt); 
                Logger::log("  Property " . $altoId . " updated in " . \DB_PREFIX . "osrs_properties. OS Property ID: " . $existingOsPropertyId . ".", 'INFO');
            } else {
                Logger::log("  Inserting new OS Property for Alto ID: " . $altoId, 'INFO');
                $stmt = self::$db->prepare("
                    INSERT INTO `" . \DB_PREFIX . "osrs_properties` (
                        `alto_id`, `pro_name`, `pro_type`, `pro_alias`, `address`, 
                        `country`, `state`, `city`, `postcode`, `pro_small_desc`, `pro_full_desc`,
                        `price`, `price_text`, `curr`, `bed_room`, `bath_room`, `rooms`, 
                        `lat_add`, `long_add`, `ref`, `agent_id`, `published`, `hits`, `approved`, `access`,
                        `created`, `modified`, `hits_day`, `hits_week`, `hits_month`, `hits_total`, `company_id`,
                        `square_feet`, `lot_size`, `built_on` 
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, 0, 0, 0, 0, ?,
                        ?, ?, ?
                    )
                ");
                $success = $stmt->execute([
                    $altoId, $displayAddress, $propertyTypeId, self::createSlug($displayAddress), 
                    $concatAddress, 
                    $countryId, $stateId, $cityId, $postcode, $summary, $description,
                    $priceValue, $displayText, $currencyId, 
                    $bedrooms, $bathrooms, $receptions,
                    $latitude, $longitude,
                    $propertyRef, 
                    $agentUserId, $osPropertyStatus, 0, 1, 1, 
                    $createdDate, $modifiedDate, 
                    $companyJoomlaId,
                    $totalFloorAreaSqft, $totalLandAreaSqft, $yearBuilt
                ]);
                $osrsPropertyId = self::$db->lastInsertId();
                unset($stmt); 
                Logger::log("  New OS Property created with ID: " . $osrsPropertyId . " for Alto ID: " . $altoId . ".", 'INFO');
            }

            // Process images (placeholder for now)
            if (isset($propertyXmlObject->images) && $propertyXmlObject->images->image) {
                foreach ($propertyXmlObject->images->image as $imageNode) {
                    $imageUrl = (string)$imageNode->url;
                    $imageCaption = (string)$imageNode->caption;
                    Logger::log("    Image found for property " . $altoId . ": " . $imageUrl, 'INFO');
                }
            }

            // Process features (placeholder for now)
            if (isset($propertyXmlObject->features) && $propertyXmlObject->features->feature) {
                foreach ($propertyXmlObject->features->feature as $feature) {
                    $featureText = (string)$feature;
                    Logger::log("    Feature found for property " . $altoId . ": " . $featureText, 'INFO');
                }
            }

            return $success;

        } catch (\PDOException $e) {
            Logger::log("Database error mapping property " . ($altoId ?? 'N/A') . ": " . $e->getMessage(), 'CRITICAL');
            return false;
        } catch (\Exception $e) {
            Logger::log("General error mapping property " . ($altoId ?? 'N/A') . ": " . $e->getMessage(), 'CRITICAL');
            return false;
        }
    }
}
