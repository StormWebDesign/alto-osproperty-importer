<?php
namespace Joomla\Plugin\Osproperty\Altoimport\Service;

use Joomla\CMS\Factory;

class XmlStorage
{
    public function store(string $propId, string $xml): void
    {
        // Insert raw XML into #__osrs_xml_details
    }

    public function clear(string $propId): void
    {
        // Remove entry after successful import
    }
}