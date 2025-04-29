<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Altoimporter
 * @copyright   Copyright (C) 2025 Storm Web Design
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Plugin\System\Altoimporter;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Plugin\System\Altoimporter\Services\AltoApiService;

class PlgSystemAltoimporter extends CMSPlugin
{
    protected $app;
    protected $autoloadLanguage = true;

    /**
     * Run via Scheduled Task
     */
    public function onAltoimporterTaskImport()
    {
        return $this->performImport();
    }

    /**
     * Run via Manual Import AJAX call
     */
    public function onAjaxAltoimporterDoImport()
    {
        try
        {
            $this->performImport();
            return new JsonResponse(['message' => Text::_('PLG_SYSTEM_ALTOIMPORTER_IMPORT_SUCCESS')]);
        }
        catch (\Exception $e)
        {
            return new JsonResponse($e->getMessage(), true);
        }
    }

    /**
     * Perform the property import
     *
     * @return bool
     * @throws \RuntimeException
     */
    protected function performImport(): bool
    {
        $params   = $this->params;
        $apiKey   = trim($params->get('api_key', ''));
        $username = trim($params->get('username', ''));
        $password = trim($params->get('password', ''));
        $logLevel = $params->get('log_level', 'info');

        if (!$apiKey || !$username || !$password)
        {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_ALTOIMPORTER_ERR_CREDENTIALS'));
        }

        // Use the API Service to do the import
        $service = new AltoApiService($apiKey, $username, $password, $logLevel);
        $service->importAllProperties();

        return true;
    }

    /**
     * Inject the Manual Import button into the plugin settings dynamically
     */
    public function onBeforeCompileHead()
    {
        if (!$this->app->isClient('administrator'))
        {
            return;
        }

        $input = $this->app->input;
        if (
            $input->getCmd('option') !== 'com_plugins' ||
            $input->getCmd('plugin') !== 'altoimporter'
        ) {
            return;
        }

        $doc = Factory::getDocument();
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=altoimporter&group=system&format=json&task=doImport';

        $js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    const fieldset = document.querySelector('fieldset.adminform > .control-group:last-child .controls');
    if (fieldset) {
        const button = document.createElement('button');
        button.className = 'btn btn-primary';
        button.textContent = 'Run Manual Import';
        button.style.marginTop = '10px';
        fieldset.appendChild(button);

        const log = document.createElement('div');
        log.id = 'alto-import-log';
        log.style.marginTop = '10px';
        fieldset.appendChild(log);

        button.addEventListener('click', function () {
            button.disabled = true;
            log.innerHTML = '<p>Import started...</p>';

            fetch('$ajaxUrl')
                .then(response => response.json())
                .then(data => {
                    button.disabled = false;
                    if (data.success) {
                        log.innerHTML = '<p><strong>Success:</strong> ' + data.data.message + '</p>';
                    } else {
                        log.innerHTML = '<p><strong>Error:</strong> ' + (data.message || 'Unknown error') + '</p>';
                    }
                })
                .catch(error => {
                    button.disabled = false;
                    log.innerHTML = '<p><strong>AJAX Error:</strong> ' + error.message + '</p>';
                });
        });
    }
});
JS;

        $doc->addScriptDeclaration($js);
    }
}
