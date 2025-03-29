<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Altoimporter
 *
 * @copyright   Copyright (C) 2024
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;

class PlgSystemAltoimporterInstallerScript implements InstallerScriptInterface
{
    /**
     * Method to run before install/update/uninstall.
     */
    public function preflight(string $type, InstallerAdapter $parent): void
    {
        // Nothing needed here for now
    }

    /**
     * Method to install the plugin.
     */
    public function install(InstallerAdapter $parent): void
    {
        $this->addAltoIdColumn();
    }

    /**
     * Method to update the plugin.
     */
    public function update(InstallerAdapter $parent): void
    {
        $this->addAltoIdColumn();
    }

    /**
     * Method to uninstall the plugin.
     */
    public function uninstall(InstallerAdapter $parent): void
    {
        // No database rollback to avoid unintended data loss
    }

    /**
     * Method to run after install/update/uninstall.
     */
    public function postflight(string $type, InstallerAdapter $parent): void
    {
        // Nothing needed here for now
    }

    /**
     * Add alto_id column to osrs_properties table if it doesn't exist.
     */
    protected function addAltoIdColumn(): void
    {
        $db = Factory::getDbo();
        $prefix = $db->getPrefix();
        $table = $prefix . 'osrs_properties';

        try {
            $columns = $db->getTableColumns($table);

            if (!array_key_exists('alto_id', $columns)) {
                $query = "ALTER TABLE `$table` ADD `alto_id` VARCHAR(64) NULL DEFAULT NULL AFTER `id`";
                $db->setQuery($query)->execute();
            }
        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage('Error modifying osrs_properties table: ' . $e->getMessage(), 'error');
        }
    }
}
