<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Ospropertyimporter
 *
 * @copyright   Copyright (C) 2025 Storm Web Design
 * @author      Russell English
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\System\Ospropertyimporter;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;

/**
 * OS Property Importer Plugin.
 */
final class Ospropertyimporter extends CMSPlugin
{
    protected $app;

    public function onAfterInitialise(): void
    {
        // Optionally add import execution logic here
    }

    public function onAfterRender(): void
    {
        if ($this->app->isClient('administrator') && $this->app->input->get('option') === 'com_plugins' && $this->app->input->get('view') === 'plugin')
        {
            HTMLHelper::_('stylesheet', 'plg_system_ospropertyimporter/admin.css', ['version' => 'auto', 'relative' => true]);
        }
    }
}
