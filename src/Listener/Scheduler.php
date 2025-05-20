<?php
namespace Joomla\Plugin\Osproperty\Altoimport\Listener;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;

class Scheduler extends CMSPlugin
{
    public function onAfterRoute()
    {
        // Trigger sync if cron_enabled param is set
        if (Factory::getApplication()->isClient('administrator') && $this->params->get('cron_enabled')) {
            $client = new \Joomla\Plugin\Osproperty\Altoimport\Service\AltoClient($this->params);
            $sync   = new \Joomla\Plugin\Osproperty\Altoimport\Service\PropertySync($client);
            $sync->syncDelta();
        }
    }
}