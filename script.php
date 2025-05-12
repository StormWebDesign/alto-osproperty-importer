<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Altoimporter
 * @copyright   Copyright (C) 2025 Storm Web Design
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;

class PlgSystemAltoimporterInstallerScript implements InstallerScriptInterface
{
    /**
     * Runs before install/update/uninstall.
     */
    public function preflight(string $type, InstallerAdapter $parent): void
    {
        // Nothing needed for now
    }

    /**
     * Installs the plugin and ensures DB column exists.
     */
    public function install(InstallerAdapter $parent): bool
    {
        $this->addAltoIdColumn();
        return true;
    }

    /**
     * Updates the plugin and ensures DB column exists.
     */
    public function update(InstallerAdapter $parent): bool
    {
        $this->addAltoIdColumn();
        return true;
    }

    /**
     * Handles post-install/update actions.
     */
    public function postflight(string $type, InstallerAdapter $parent): void
    {
        // Nothing needed
    }

    /**
     * Uninstall logic (optional).
     */
    public function uninstall(InstallerAdapter $parent): bool
    {
        // Avoid deleting column to prevent data loss
        return true;
    }

    /**
     * Adds `alto_id` column to the OS Property table if not present.
     */
    private function addAltoIdColumn(): void
    {
        $db     = Factory::getDbo();
        $prefix = $db->getPrefix();
        $table  = $prefix . 'osrs_properties';

        try {
            $columns = $db->getTableColumns($table);

            if (!array_key_exists('alto_id', $columns)) {
                $query = "ALTER TABLE `$table` ADD `alto_id` VARCHAR(64) NULL DEFAULT NULL AFTER `id`";
                $db->setQuery($query)->execute();
            }
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage('Failed to update table structure: ' . $e->getMessage(), 'error');
        }
    }
}
