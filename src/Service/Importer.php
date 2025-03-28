<?php

namespace Joomla\Plugin\System\Ospropertyimporter\Service;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;

use Joomla\CMS\Http\HttpFactory;
use Joomla\Registry\Registry;

class Importer
{
    protected string $apiUrl;
    protected string $apiKey;
    protected \Joomla\CMS\Http\Http $http;

    public function __construct()
    {
        $params = PluginHelper::getPlugin('system', 'ospropertyimporter')->params;
        $registry = new Registry($params);
        $this->apiUrl = rtrim($registry->get('api_url'), '/');
        $this->apiKey = $registry->get('api_key');
        $this->http = HttpFactory::getHttp();
    }

    public function run(): void
    {
        try {
            $properties = $this->fetchProperties();

            foreach ($properties as $property) {
                $this->importProperty($property);
            }
        } catch (\Exception $e) {
            Log::add('OS Property Importer Error: ' . $e->getMessage(), Log::ERROR, 'ospropertyimporter');
        }
    }

    protected function fetchProperties(): array
    {
        $url = $this->apiUrl . '/v1/property';
        $headers = ['apiKey' => $this->apiKey];

        $response = $this->http->get($url, $headers);

        if ($response->code !== 200) {
            throw new \RuntimeException('Failed to fetch properties: ' . $response->body);
        }

        $data = json_decode($response->body, true);
        return $data['data'] ?? [];
    }

    protected function importProperty(array $data): void
    {
        // Core logic to convert Alto data to OS Property records
        // Add logic here to map fields and insert/update OS Property DB records
        // E.g. $data['address'], $data['price'], etc.

        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select('id')
            ->from('#__osrs_properties')
            ->where('alto_id = ' . $db->quote($data['id']));

        $db->setQuery($query);
        $existingId = $db->loadResult();

        $record = [
            'title' => $data['address']['displayAddress'] ?? 'Untitled Property',
            'alias' => \Joomla\CMS\Helper\RouteHelper::stringURLSafe($data['address']['displayAddress']),
            'price' => $data['priceInformation']['price'],
            'description' => $data['description']['summary'] ?? '',
            'alto_id' => $data['id'],
            'published' => 1,
        ];

        if ($existingId) {
            $db->updateObject('#__osrs_properties', (object) $record, 'alto_id');
        } else {
            $db->insertObject('#__osrs_properties', (object) $record);
        }

        Log::add('Imported property ID: ' . $data['id'], Log::INFO, 'ospropertyimporter');
    }
}
