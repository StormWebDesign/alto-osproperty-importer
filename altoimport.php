<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;

class PlgOspropertyAltoimport extends CMSPlugin
{
    public function onAfterInitialise()
    {
        // Optionally trigger sync here or rely on CLI
    }

    protected function sync(): void
    {
        $client = new \Joomla\Plugin\Osproperty\Altoimport\Service\AltoClient($this->params);
        $sync   = new \Joomla\Plugin\Osproperty\Altoimport\Service\PropertySync($client);
        $sync->syncDelta();
    }
}