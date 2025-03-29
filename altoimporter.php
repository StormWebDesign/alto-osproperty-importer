<?php

namespace Joomla\Plugin\System\Altoimporter;

defined('_JEXEC') || die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

use Joomla\Plugin\System\Altoimporter\Services\AltoApiService;

class Plugin extends CMSPlugin
{
    protected $autoloadLanguage = true;

    protected CMSApplicationInterface $app;

    /**
     * onAfterInitialise - Joomla lifecycle hook
     */
    public function onAfterInitialise(): void
    {
        // Optional: Do something after Joomla has initialised
    }

    /**
     * Used by Joomla Scheduled Tasks or Manual Trigger to start the import
     */
    public function runImport(): void
    {
        try {
            $logLevel = $this->params->get('log_level', 'info');
            Log::addLogger(
                ['text_file' => 'altoimporter.log.php'],
                Log::ALL,
                ['plg_system_altoimporter']
            );

            Log::add('Alto Import started', Log::INFO, 'plg_system_altoimporter');

            $apiKey = $this->params->get('api_key');
            $username = $this->params->get('username');
            $password = $this->params->get('password');

            if (!$apiKey || !$username || !$password) {
                Log::add('Missing Alto API credentials.', Log::ERROR, 'plg_system_altoimporter');
                return;
            }

            // Call the service
            $service = new AltoApiService($apiKey, $username, $password);
            $properties = $service->fetchProperties();

            foreach ($properties as $property) {
                $service->importProperty($property);
            }

            Log::add('Alto Import completed successfully.', Log::INFO, 'plg_system_altoimporter');

        } catch (\Exception $e) {
            Log::add('Alto Import failed: ' . $e->getMessage(), Log::ERROR, 'plg_system_altoimporter');
        }
    }

    /**
     * Optional method: Adds menu item in admin if needed (not required for core plugin use)
     */
    public function onAfterDispatch()
    {
        $input = $this->app->input;

        if (!$this->app->isClient('administrator') || $input->getCmd('option') !== 'com_plugins' || $input->getCmd('plugin') !== 'altoimporter') {
            return;
        }

        // Add import toolbar button if on plugin settings page
        $bar = Toolbar::getInstance('toolbar');
        $bar->appendButton('Custom', '<a class="btn btn-small btn-success" href="' . Route::_('index.php?option=com_plugins&task=plugin.importAlto&plugin=altoimporter&group=system') . '">' . Text::_('PLG_SYSTEM_ALTOIMPORTER_MANUAL_IMPORT') . '</a>', 'import');
    }

    /**
     * Custom task handler (when clicking manual import in admin)
     */
    public function onCustomTask($task)
    {
        if ($task === 'importAlto') {
            $this->runImport();

            $this->app->enqueueMessage(Text::_('PLG_SYSTEM_ALTOIMPORTER_IMPORT_COMPLETE'), 'message');
            $this->app->redirect(Route::_('index.php?option=com_plugins&view=plugins', false));
        }
    }
}
