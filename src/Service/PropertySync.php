<?php
namespace Joomla\Plugin\Osproperty\Altoimport\Service;

class PropertySync
{
    protected AltoClient $client;

    public function __construct(AltoClient $client)
    {
        $this->client = $client;
    }

    public function syncAll(): void
    {
        // Full import logic
    }

    public function syncDelta(): void
    {
        // Delta import logic
    }

    protected function importProperty(\SimpleXMLElement $xml): void
    {
        // Mapping and upsert logic
    }
}