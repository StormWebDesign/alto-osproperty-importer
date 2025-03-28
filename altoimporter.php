<?php

namespace Joomla\Plugin\System\Altoimporter;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Filesystem\File;
use Joomla\Plugin\System\Altoimporter\Models\OsProperty;
use Joomla\CMS\Http\HttpFactory;

defined('_JEXEC') or die;

class PluginSystemAltoimporter extends CMSPlugin
{
    protected $autoloadLanguage = true;

    public function onAfterInitialise()
    {
        $app = Factory::getApplication();

        if ($app->isClient('administrator') && $app->input->get('altoimport', '', 'cmd') === 'run') {
            $this->importProperties();
        }
    }

    private function importProperties()
    {
        $http = HttpFactory::getHttp();
        $apiKey = $this->params->get('api_key');
        $username = $this->params->get('username');
        $password = $this->params->get('password');

        $url = 'https://api.altosoftware.co.uk/export/1.0/property';
        $headers = ['apiKey: ' . $apiKey];
        $options = [
            'auth' => [$username, $password]
        ];

        try {
            $response = $http->get($url, $headers, $options);
            $properties = json_decode($response->body, true)['data'];

            foreach ($properties as $property) {
                OsProperty::updateOrCreate(
                    ['alto_id' => $property['id']],
                    [
                        'ref' => $property['uniqueReference'],
                        'pro_name' => $property['summary'],
                        'pro_alias' => $this->generateAlias($property['summary']),
                        'pro_browser_title' => $property['summary'],
                        'pro_small_desc' => $property['description'],
                        'pro_full_desc' => $property['description'],
                        'price' => $property['price']['amount'] ?? 0,
                        'bed_room' => $property['bedrooms'],
                        'bath_room' => $property['bathrooms'],
                        'square_feet' => $property['area']['value'] ?? 0,
                        'address' => $property['address']['displayAddress'],
                        'postcode' => $property['address']['postcode'],
                        'lat_add' => $property['address']['latitude'] ?? '',
                        'long_add' => $property['address']['longitude'] ?? '',
                        'published' => 1
                    ]
                );
            }

            Factory::getApplication()->enqueueMessage('Alto Import Complete: ' . count($properties) . ' properties processed.');
        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage('Alto Import Failed: ' . $e->getMessage(), 'error');
        }
    }

    private function generateAlias($string)
    {
        return strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($string)));
    }
}
