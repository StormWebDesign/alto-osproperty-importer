<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;

class PlgSystemAltoimporterInstallerScript implements InstallerScriptInterface
{
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function install(InstallerAdapter $adapter): bool
    {
        $this->addAltoIdColumn();
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        $this->addAltoIdColumn();
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    protected function addAltoIdColumn(): void
    {
        $db     = Factory::getDbo();
        $table  = $db->getPrefix() . 'osrs_properties';
        try
        {
            $cols = $db->getTableColumns($table);
            if (!isset($cols['alto_id']))
            {
                $db->setQuery("ALTER TABLE `$table` ADD `alto_id` VARCHAR(64) NULL AFTER `id`;")->execute();
            }
        }
        catch (\Exception $e)
        {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
        }
    }
}
