<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Altoimporter
 * @copyright   Copyright (C) 2025 Storm Web Design
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Plugin\System\Altoimporter\Service;

\defined('_JEXEC') or die;

use Joomla\Plugin\System\Altoimporter\Models\OsProperty;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;

class AltoApiService
{
    private string $apiKey;
    private string $username;
    private string $password;
    private string $endpoint = 'https://api.altosoftware.co.uk/PropertyFeedService/v13'; // Confirm if this is the correct endpoint
    private \Joomla\CMS\Http\Http $http;

    public function __construct(string $apiKey, string $username, string $password)
    {
        $this->apiKey    = $apiKey;
        $this->username  = $username;
        $this->password  = $password;
        $this->http      = HttpFactory::getHttp();
    }

    /**
     * Main import handler
     */
    public function importAllProperties(): void
    {
        $properties = $this->fetchProperties();

        foreach ($properties as $property)
        {
            $this->storeProperty($property);
        }

        Log::add('AltoImporter: Imported ' . count($properties) . ' properties', Log::INFO, 'plg_system_altoimporter');
    }

    /**
     * Call Alto API and return decoded property list
     */
    private function fetchProperties(): array
    {
        $url = $this->endpoint . '/property/list';
        $headers = [
            'x-api-key' => $this->apiKey,
            'Accept'    => 'application/json',
        ];

        $options = [
            'auth' => [$this->username, $this->password],
        ];

        $response = $this->http->get($url, $headers, $options);

        if ($response->code !== 200) {
            throw new \RuntimeException('Failed to fetch properties: ' . $response->body);
        }

        $data = json_decode($response->body, true);

        return $data['data'] ?? [];
    }

    /**
     * Insert or update a property
     */
    private function storeProperty(array $data): void
    {
        if (empty($data['id'])) {
            return;
        }

        OsProperty::updateOrCreate(
            ['alto_id' => $data['id']],
            [
                'alto_id'     => $data['id'],
                'pro_name'    => $data['summary'] ?? 'Untitled Property',
                'ref'         => $data['reference'] ?? '',
                'address'     => $data['address']['display'] ?? '',
                'postcode'    => $data['address']['postcode'] ?? '',
                'price'       => $data['price']['priceValue'] ?? null,
                'bed_room'    => $data['bedrooms'] ?? null,
                'bath_room'   => $data['bathrooms'] ?? null,
                'lat_add'     => $data['location']['latitude'] ?? null,
                'long_add'    => $data['location']['longitude'] ?? null,
                'published'   => 1,
            ]
        );
    }
}
