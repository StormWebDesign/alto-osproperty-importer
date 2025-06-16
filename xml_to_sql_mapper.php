<?php

namespace AltoSync\Mapper;

// Include the configuration file for database credentials and other settings
require_once __DIR__ . '/../config.php'; // Adjust path based on xml_to_sql_mapper.php's location relative to config.php

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
                    'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                    DB_USER,
                    DB_PASS,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
            } catch (\PDOException $e) {
                error_log("Database connection failed in OsPropertyMapper: " . $e->getMessage());
                die("Database connection failed in mapper.");
            }
        }
    }

    /**
     * Maps branch details from XML to database tables.
     * @param string $branchXml The XML string for a single branch.
     * @return bool True on success, false on failure.
     */
    public static function mapBranchDetailsToDatabase($branchXml) {
        self::initDb();

        try {
            $xml = \simplexml_load_string($branchXml);
            if ($xml === false) {
                error_log("Failed to parse branch XML string: " . $branchXml);
                return false;
            }

            $firmid = (string)$xml->firmid;
            $branchid = (string)$xml->branchid;
            $branchName = (string)$xml->name;
            $branchUrl = (string)$xml->url;

            // Address
            $addressLine1 = (string)$xml->address->line1;
            $addressLine2 = (string)$xml->address->line2; // Assuming line2 exists
            $addressLine3 = (string)$xml->address->line3; // Assuming line3 exists
            $town = (string)$xml->address->town;
            $postcode = (string)$xml->address->postcode;
            $country = (string)$xml->address->country;

            // Contact
            $email = (string)$xml->email;
            $telephone = (string)$xml->telephone;
            $fax = (string)$xml->fax;
            $website = (string)$xml->website;

            // Get or create lookup IDs for city, country, company
            $cityId = self::getOrCreateLookupId(self::$db, 'osrs_cities', 'city', $town);
            $countryId = self::getOrCreateLookupId(self::$db, 'osrs_countries', 'country_name', $country);
            $companyId = self::getOrCreateLookupId(self::$db, 'osrs_companies', 'company_name', $branchName); // Using branch name as company name

            // Prepare address for OS Property branches table (assumed structure)
            $fullAddress = trim($addressLine1 . ' ' . $addressLine2 . ' ' . $addressLine3);


            // Determine if it's an update or insert for the main OS Property branches table
            $stmt = self::$db->prepare("SELECT id FROM `" . DB_PREFIX . "osrs_branches` WHERE branch_id = ?");
            $stmt->execute([$branchid]);
            $existingOsBranchId = $stmt->fetchColumn();

            if ($existingOsBranchId) {
                // Update existing branch
                $stmt = self::$db->prepare("
                    UPDATE `" . DB_PREFIX . "osrs_branches` SET
                        `branch_name` = ?,
                        `branch_address` = ?,
                        `city` = ?,
                        `country` = ?,
                        `postcode` = ?,
                        `branch_email` = ?,
                        `branch_phone` = ?,
                        `branch_fax` = ?,
                        `website` = ?,
                        `company_id` = ?,
                        `published` = 1,
                        `modified` = NOW()
                    WHERE `id` = ?
                ");
                $stmt->execute([
                    $branchName, $fullAddress, $cityId, $countryId, $postcode,
                    $email, $telephone, $fax, $website, $companyId,
                    $existingOsBranchId
                ]);
                error_log("  Branch ID " . $branchid . " updated in " . DB_PREFIX . "osrs_branches.");
            } else {
                // Insert new branch
                $stmt = self::$db->prepare("
                    INSERT INTO `" . DB_PREFIX . "osrs_branches` (
                        `branch_id`, `branch_name`, `branch_address`, `city`, `country`, `postcode`,
                        `branch_email`, `branch_phone`, `branch_fax`, `website`, `company_id`,
                        `hits`, `ordering`, `published`, `created`, `modified`
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        0, 0, 1, NOW(), NOW()
                    )
                ");
                $stmt->execute([
                    $branchid, $branchName, $fullAddress, $cityId, $countryId, $postcode,
                    $email, $telephone, $fax, $website, $companyId
                ]);
                error_log("  New Branch ID " . $branchid . " inserted into " . DB_PREFIX . "osrs_branches.");
            }

            return true;

        } catch (\PDOException $e) {
            error_log("Database error mapping branch: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("General error mapping branch: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Maps property details from XML to database tables.
     * @param string $propertyXml The XML string for a single property.
     * @param string $branchAltoId The Alto branch ID this property belongs to.
     * @return bool True on success, false on failure.
     */
    public static function mapPropertyDetailsToDatabase($propertyXml, $branchAltoId) {
        self::initDb();

        try {
            $xml = \simplexml_load_string($propertyXml);
            if ($xml === false) {
                error_log("Failed to parse property XML string: " . $propertyXml);
                return false;
            }

            $altoId = (string)$xml->id;
            $webStatus = (string)$xml->web_status;
            $propertyType = (string)$xml->property_type;
            $bedrooms = (int)$xml->bedrooms;
            $bathrooms = (int)$xml->bathrooms;
            $receptions = (int)$xml->receptions;

            // Address
            $addressLine1 = (string)$xml->address->line1;
            $addressLine2 = (string)$xml->address->line2;
            $addressLine3 = (string)$xml->address->line3;
            $town = (string)$xml->address->town;
            $county = (string)$xml->address->county;
            $postcode = (string)$xml->address->postcode;
            $country = (string)$xml->address->country;
            $displayAddress = (string)$xml->address->display_address;

            // Price
            $priceValue = (float)$xml->price->value;
            $priceQualifier = (string)$xml->price->qualifier;
            $displayText = (string)$xml->price->display_text;

            $summary = (string)$xml->summary;
            $description = (string)$xml->description;
            $dateAdded = (string)$xml->date_added; //YYYY-MM-DD
            $dateLastModified = (string)$xml->date_last_modified; //YYYY-MM-DD

            $tenure = (string)$xml->tenure;
            $yearBuilt = (int)$xml->year_built;

            // EPC
            $epcCurrentEnergyEfficiency = (string)$xml->epc->current_energy_efficiency;
            $epcCurrentEnvironmentalImpact = (string)$xml->epc->current_environmental_impact;

            // Floor Area
            $totalFloorAreaSqft = (float)$xml->floor_area->total_floor_area_sqft;

            // Land Area
            $totalLandAreaSqft = (float)$xml->land_area->total_land_area_sqft;

            // Negotiator
            $negotiatorId = (string)$xml->negotiator->id;
            $negotiatorName = (string)$xml->negotiator->name;
            $negotiatorEmail = (string)$xml->negotiator->email;
            $negotiatorPhone = (string)$xml->negotiator->phone;

            $latitude = (float)$xml->latitude;
            $longitude = (float)$xml->longitude;

            // Lookup IDs
            $cityId = self::getOrCreateLookupId(self::$db, 'osrs_cities', 'city', $town);
            $stateId = self::getOrCreateLookupId(self::$db, 'osrs_states', 'state', $county); // Mapping county to state
            $countryId = self::getOrCreateLookupId(self::$db, 'osrs_countries', 'country_name', $country);
            $propertyTypeId = self::getOrCreateLookupId(self::$db, 'osrs_types', 'type_name', $propertyType);
            $agentId = self::getOrCreateAgentId(self::$db, $negotiatorId, $negotiatorName, $negotiatorEmail, $negotiatorPhone);

            // Determine OS Property status
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
                    $osPropertyStatus = 0; // Unpublished/Archived
                    break;
                default:
                    $osPropertyStatus = 1; // Default to published if unknown
                    error_log("  Unknown web_status for property " . $altoId . ": " . $webStatus . ". Defaulting to published.");
                    break;
            }

            // Get Joomla branch ID from alto_branch_id
            $stmt = self::$db->prepare("SELECT id FROM `" . DB_PREFIX . "osrs_branches` WHERE branch_id = ?");
            $stmt->execute([$branchAltoId]);
            $branchJoomlaId = $stmt->fetchColumn();
            if (!$branchJoomlaId) {
                error_log("  Could not find OS Property branch ID for Alto branch ID: " . $branchAltoId . ". Property " . $altoId . " will not be associated with a branch.");
                $branchJoomlaId = 0; // Set to 0 or a default if no matching branch found
            }


            // Construct full address string for `pro_address`
            $proAddress = implode(', ', array_filter([
                $addressLine1, $addressLine2, $addressLine3, $town, $county, $postcode, $country
            ]));


            // Check if property exists in OS Property by alto_id
            $stmt = self::$db->prepare("SELECT id FROM `" . DB_PREFIX . "osrs_properties` WHERE alto_id = ?");
            $stmt->execute([$altoId]);
            $existingOsPropertyId = $stmt->fetchColumn();

            // Prepare dates for MySQL
            $createdDate = ($dateAdded ? date('Y-m-d H:i:s', strtotime($dateAdded)) : date('Y-m-d H:i:s'));
            $modifiedDate = ($dateLastModified ? date('Y-m-d H:i:s', strtotime($dateLastModified)) : date('Y-m-d H:i:s'));

            // Use the agent_id (Joomla user ID) from the agent lookup
            $agentUserId = $agentId; // The ID returned by getOrCreateAgentId is the Joomla user ID


            if ($existingOsPropertyId) {
                // Update existing property
                $stmt = self::$db->prepare("
                    UPDATE `" . DB_PREFIX . "osrs_properties` SET
                        `pro_name` = ?,
                        `pro_type` = ?,
                        `pro_alias` = ?,
                        `pro_address` = ?,
                        `country` = ?,
                        `state` = ?,
                        `city` = ?,
                        `postcode` = ?,
                        `pro_small_desc` = ?,
                        `pro_full_desc` = ?,
                        `price` = ?,
                        `price_text` = ?,
                        `currency_id` = ?, -- Assuming default currency ID, adjust if needed
                        `beds` = ?,
                        `bathrooms` = ?,
                        `rooms` = ?, -- Using receptions for rooms
                        `lat` = ?,
                        `long` = ?,
                        `pro_url` = ?, -- Using altoId as part of a unique URL, adjust if needed
                        `agent_id` = ?,
                        `published` = ?,
                        `hits` = `hits`,
                        `approved` = 1,
                        `access` = 1,
                        `language` = '*',
                        `modified` = ?,
                        `branch_id` = ?
                    WHERE `id` = ?
                ");
                $stmt->execute([
                    $displayAddress, $propertyTypeId, \JFilterOutput::stringURLSafe($displayAddress),
                    $proAddress, $countryId, $stateId, $cityId, $postcode,
                    $summary, $description, $priceValue, $displayText, 1, // Default currency
                    $bedrooms, $bathrooms, $receptions,
                    $latitude, $longitude,
                    $altoId, // Using altoId as pro_url for unique reference
                    $agentUserId, $osPropertyStatus,
                    $modifiedDate, $branchJoomlaId,
                    $existingOsPropertyId
                ]);
                error_log("  Property " . $altoId . " updated in " . DB_PREFIX . "osrs_properties.");
            } else {
                // Insert new property
                $stmt = self::$db->prepare("
                    INSERT INTO `" . DB_PREFIX . "osrs_properties` (
                        `alto_id`, `pro_name`, `pro_type`, `pro_alias`, `pro_address`,
                        `country`, `state`, `city`, `postcode`, `pro_small_desc`, `pro_full_desc`,
                        `price`, `price_text`, `currency_id`, `beds`, `bathrooms`, `rooms`,
                        `lat`, `long`, `pro_url`, `agent_id`, `published`, `hits`, `approved`, `access`,
                        `language`, `created`, `modified`, `hits_day`, `hits_week`, `hits_month`, `hits_total`, `branch_id`
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, 0, 0, 0, 0, ?
                    )
                ");
                $stmt->execute([
                    $altoId, $displayAddress, $propertyTypeId, \JFilterOutput::stringURLSafe($displayAddress), $proAddress,
                    $countryId, $stateId, $cityId, $postcode, $summary, $description,
                    $priceValue, $displayText, 1, // Default currency
                    $bedrooms, $bathrooms, $receptions,
                    $latitude, $longitude,
                    $altoId, // Using altoId as pro_url for unique reference
                    $agentUserId, $osPropertyStatus, 0, 1, 1,
                    '*', $createdDate, $modifiedDate, $branchJoomlaId
                ]);
                error_log("  New Property " . $altoId . " inserted into " . DB_PREFIX . "osrs_properties.");
            }

            // Process images (this needs careful implementation to download and store)
            // This is a placeholder for image processing
            if ($xml->images && $xml->images->image) {
                foreach ($xml->images->image as $imageNode) {
                    $imageUrl = (string)$imageNode->url;
                    $imageCaption = (string)$imageNode->caption;
                    // You'd need logic here to download the image, resize it,
                    // save it to Joomla's osproperty/properties/ property_id/ folder
                    // and insert/update records in `ix3gf_osrs_photos`
                    error_log("  Image found for property " . $altoId . ": " . $imageUrl);
                    // Example: $this->saveImage($altoId, $imageUrl, $imageCaption);
                }
            }

            // Process features (Needs mapping to OS Property features if supported)
            if ($xml->features && $xml->features->feature) {
                foreach ($xml->features->feature as $feature) {
                    $featureText = (string)$feature;
                    // You'd need logic here to map $featureText to existing OS Property features
                    // and link them to the property in `ix3gf_osrs_property_amenities`
                    error_log("  Feature found for property " . $altoId . ": " . $featureText);
                }
            }


            return true;

        } catch (\PDOException $e) {
            error_log("Database error mapping property " . $altoId . ": " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("General error mapping property " . $altoId . ": " . $e->getMessage());
            return false;
        }
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
        // Sanitize nameValue for WHERE clause and for display
        $nameValue = \trim($nameValue);
        if (\empty($nameValue)) {
            \error_log("Attempted to lookup/create " . $tableName . " with empty value for " . $nameColumn);
            return false;
        }

        $prefixedTableName = DB_PREFIX . $tableName;
        $nameColumn = \preg_replace('/[^a-zA-Z0-9_]/', '', $nameColumn); // Sanitize column name to prevent injection

        try {
            // Check if the value already exists
            $stmt = $db->prepare("SELECT id FROM `" . $prefixedTableName . "` WHERE `" . $nameColumn . "` = ?");
            $stmt->execute([$nameValue]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                return (int)$existingId;
            } else {
                // Insert the new value
                $stmt = $db->prepare("INSERT INTO `" . $prefixedTableName . "` (`" . $nameColumn . "`, published) VALUES (?, 1)");
                $stmt->execute([$nameValue]);
                return (int)$db->lastInsertId();
            }
        } catch (\PDOException $e) {
            error_log("Database error in getOrCreateLookupId for " . $prefixedTableName . " (" . $nameValue . "): " . $e->getMessage());
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
        // We need a valid email to create a Joomla user. If not provided, log and return false.
        if (empty($negotiatorEmail) || !filter_var($negotiatorEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("Cannot create agent: Invalid or empty email provided for negotiator ID " . $negotiatorId);
            return false;
        }

        // Check if an agent already exists by Alto ID (custom column in Joomla users table)
        // You would need to add an 'alto_negotiator_id' column to your Joomla users table (`#__users`)
        $stmt = $db->prepare("SELECT id FROM `" . DB_PREFIX . "users` WHERE `alto_negotiator_id` = ?");
        $stmt->execute([$negotiatorId]);
        $existingJoomlaUserId = $stmt->fetchColumn();

        if ($existingJoomlaUserId) {
            // Update existing user (e.g., name, email, phone)
            $stmt = $db->prepare("
                UPDATE `" . DB_PREFIX . "users` SET
                    `name` = ?, `email` = ?, `modified` = NOW()
                WHERE `id` = ?
            ");
            $stmt->execute([$negotiatorName, $negotiatorEmail, $existingJoomlaUserId]);
            error_log("  Updated existing agent (Joomla ID: " . $existingJoomlaUserId . ") for Alto Negotiator ID: " . $negotiatorId);
            return (int)$existingJoomlaUserId;
        } else {
            // Create a new Joomla user
            $username = strtolower(str_replace(' ', '', $negotiatorName)) . '_' . $negotiatorId;
            // Joomla requires unique usernames. Add a timestamp or random string if necessary.
            $username = substr(preg_replace('/[^a-z0-9]/', '', $username), 0, 150); // Sanitize and limit length
            $username = $username . '_' . uniqid(); // Ensure uniqueness

            // Generate a random password (Joomla hashes passwords)
            $password = password_hash(substr(md5(rand()), 0, 8), PASSWORD_DEFAULT);

            try {
                $stmt = $db->prepare("
                    INSERT INTO `" . DB_PREFIX . "users` (
                        `name`, `username`, `email`, `password`, `registerDate`, `lastvisitDate`, `activation`,
                        `sendEmail`, `block`, `requireReset`, `alto_negotiator_id`
                    ) VALUES (
                        ?, ?, ?, ?, NOW(), '0000-00-00 00:00:00', '',
                        0, 0, 0, ?
                    )
                ");
                $stmt->execute([$negotiatorName, $username, $negotiatorEmail, $password, $negotiatorId]);
                $newJoomlaUserId = $db->lastInsertId();

                if ($newJoomlaUserId) {
                    error_log("  Created new Joomla user (ID: " . $newJoomlaUserId . ") for Alto Negotiator ID: " . $negotiatorId);

                    // Assign to 'Registered' group by default (or a specific 'Agent' group if you have one)
                    $groupStmt = $db->prepare("INSERT IGNORE INTO `" . DB_PREFIX . "user_usergroup_map` (user_id, group_id) VALUES (?, ?)");
                    $groupStmt->execute([$newJoomlaUserId, 2]); // 2 is typically 'Registered' in Joomla

                    // If OS Property agents are different from Joomla users, you might also need to insert into `#__osrs_agents`
                    // For now, assuming OS Property uses Joomla user IDs directly, or maps to them.
                    // This part needs specific OS Property table structure knowledge.

                    return (int)$newJoomlaUserId;
                }
            } catch (\PDOException $e) {
                // Handle duplicate username/email errors gracefully if possible
                if ($e->getCode() == 23000) { // Integrity constraint violation (e.g., duplicate unique key)
                    error_log("  Attempted to create duplicate user for negotiator " . $negotiatorId . ": " . $e->getMessage());
                    // Try to find the existing user if it's a username collision and return their ID
                    $stmt = $db->prepare("SELECT id FROM `" . DB_PREFIX . "users` WHERE `username` = ? OR `email` = ?");
                    $stmt->execute([$username, $negotiatorEmail]);
                    $existingId = $stmt->fetchColumn();
                    if ($existingId) return (int)$existingId;
                }
                error_log("Database error creating agent: " . $e->getMessage());
                return false;
            }
        }
        return false;
    }
}