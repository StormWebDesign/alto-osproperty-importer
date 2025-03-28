<?php

defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Factory;

class PlgSystemAltoimporterInstallerScript implements InstallerScript
{
    public function install(InstallerAdapter $adapter): bool
    {
        return $this->addAltoIdColumn();
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return $this->addAltoIdColumn();
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    protected function addAltoIdColumn(): bool
    {
        $db = Factory::getDbo();
        $table = $db->quoteName('#__osrs_properties');

        try {
            $columns = $db->getTableColumns($table);

            if (!isset($columns['alto_id'])) {
                $query = "ALTER TABLE $table ADD alto_id VARCHAR(255) DEFAULT NULL";
                $db->setQuery($query)->execute();
            }
        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage('Failed to add alto_id column: ' . $e->getMessage(), 'error');
            return false;
        }

        return true;
    }
}
