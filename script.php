<?php
defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Factory;

class PlgSystemAltoimporterInstallerScript implements InstallerScriptInterface
{
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
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
}
