<?php

namespace Joomla\Plugin\System\Altoimporter\Services;

defined('_JEXEC') || die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\CMS\Log\Log;
use Joomla\Plugin\System\Altoimporter\Models\OsProperty;

class AltoApiService
{
    protected string $apiKey;
    protected string $username;
    protected string $password;
    protected string $baseUrl = 'https://api.letmc.com/v13/';
    protected DatabaseDriver $db;

    public function __construct(string $apiKey, string $username, string $password)
    {
        $this->apiKey   = $apiKey;
        $this->username = $username;
        $this->password = $password;
        $this->db       = Factory::getContainer()->get(DatabaseDriver::class);
    }

    /**
     * Fetches properties from Alto v13 API
     * @return array
     */
    public function fetchProperties(): array
    {
        $url = $this->baseUrl . 'property';
        $response = $this->makeRequest($url);

        return $response['data'] ?? [];
    }

    /**
     * Imports or updates a property into the database
     * @param array $data
     */
    public function importProperty(array $data): void
    {
        $altoId = $data['id'] ?? null;

        if (!$altoId) {
            Log::add('Skipping property with missing ID', Log::WARNING, 'plg_system_altoimporter');
            return;
        }

        $payload = [
            'alto_id'       => $altoId,
            'ref'           => $data['reference'] ?? '',
            'pro_name'      => $data['address']['displayAddress'] ?? 'Unnamed Property',
            'pro_alias'     => $this->createAlias($data['address']['displayAddress'] ?? 'property'),
            'pro_small_desc'=> $data['shortDescription'] ?? '',
            'pro_full_desc' => $data['longDescription'] ?? '',
            'price'         => $data['price']['amount'] ?? 0,
            'bed_room'      => $data['bedrooms'] ?? 0,
            'bath_room'     => $data['bathrooms'] ?? 0,
            'lat_add'       => $data['location']['latitude'] ?? null,
            'long_add'      => $data['location']['longitude'] ?? null,
            'address'       => $data['address']['displayAddress'] ?? '',
            'postcode'      => $data['address']['postcode'] ?? '',
            'published'     => 1,
        ];

        OsProperty::updateOrCreate(['alto_id' => $altoId], $payload);

        Log::add("Imported/Updated property: {$payload['ref']}", Log::INFO, 'plg_system_altoimporter');
    }

    /**
     * Makes an authenticated HTTP GET request
     * @param string $url
     * @return array
     */
    protected function makeRequest(string $url): array
    {
        $headers = [
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json'
        ];

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0
        ]);

        $response = curl_exec($curl);
        $error    = curl_error($curl);

        curl_close($curl);

        if ($error || !$response) {
            Log::add('Alto API Request failed: ' . $error, Log::ERROR, 'plg_system_altoimporter');
            return [];
        }

        return json_decode($response, true) ?? [];
    }

    /**
     * Creates a URL-safe alias
     */
    protected function createAlias(string $string): string
    {
        $string = strtolower(trim($string));
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        return preg_replace('/-+/', '-', $string);
    }
}
