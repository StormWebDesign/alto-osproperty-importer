<?php
defined('_JEXEC') or die;

use Joomla\\CMS\\Installer\\InstallerScript;
use Joomla\\CMS\\Factory;

class PlgOspropertyAltoimportInstallerScript
{
    public function install($parent)
    {
        $this->createXmlDetailsTable();
    }

    public function update($parent)
    {
        $this->createXmlDetailsTable();
    }

    protected function createXmlDetailsTable(): void
    {
        $db    = Factory::getDbo();
        $query = $db->getQuery(true)
            ->createTable('#__osrs_xml_details')
            ->ifNotExists()
            ->columns([
                'id INT AUTO_INCREMENT PRIMARY KEY',
                'prop_id VARCHAR(50) NOT NULL',
                'xml MEDIUMTEXT NOT NULL',
                'created DATETIME DEFAULT CURRENT_TIMESTAMP'
            ]);
        $db->setQuery($query)->execute();
    }
}