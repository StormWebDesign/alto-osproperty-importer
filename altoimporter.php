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
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\HTML\HTMLHelper;

use Joomla\Plugin\System\Altoimporter\Services\AltoApiService;
use Joomla\Plugin\System\Altoimporter\Models\OsProperty;
use Joomla\Plugin\System\Altoimporter\Models\OsPhoto;
use Joomla\Plugin\System\Altoimporter\Models\OsPropertyCategory;
use Joomla\Plugin\System\Altoimporter\Models\OsPropertyAmenity;
use Joomla\Plugin\System\Altoimporter\Models\OsCity;
use Joomla\Plugin\System\Altoimporter\Models\OsCountry;

use Exception;

/**
 * Alto Importer Plugin
 */
class PlgSystemAltoimporter extends CMSPlugin
{
    protected $app;
    protected $autoloadLanguage = true;

    /**
     * Run scheduled task
     */
    public function onAltoimporterTaskImport()
    {
        return $this->performImport();
    }

    /**
     * Handle AJAX manual import
     */
    public function onAjaxAltoimporterDoImport()
    {
        try {
            $result = $this->performImport();
            return new JsonResponse(['message' => Text::_('PLG_SYSTEM_ALTOIMPORTER_IMPORT_SUCCESS')]);
        } catch (Exception $e) {
            return new JsonResponse($e->getMessage(), true);
        }
    }

    /**
     * Perform the import
     */
    protected function performImport(): bool
    {
        $params = $this->params;

        $apiKey    = $params->get('api_key');
        $username  = $params->get('username');
        $password  = $params->get('password');
        $logLevel  = $params->get('log_level', 'info');

        if (!$apiKey || !$username || !$password) {
            throw new \RuntimeException('Missing API credentials in plugin settings.');
        }

        // Create service and run import
        $service = new AltoApiService($apiKey, $username, $password, $logLevel);
        $service->importAllProperties();

        return true;
    }

    /**
     * Inject JS and button logic for manual import
     */
    public function onBeforeCompileHead()
    {
        if (!$this->app->isClient('administrator')) {
            return;
        }

        $input = $this->app->input;
        if (
            $input->getCmd('option') !== 'com_plugins' ||
            $input->getCmd('plugin') !== 'altoimporter'
        ) {
            return;
        }

        /** @var HtmlDocument $doc */
        $doc = Factory::getDocument();

        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=altoimporter&group=system&format=json&task=doImport';

        $js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('altoManualImport');
    const log = document.getElementById('altoImportLog');

    if (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            log.innerHTML = '<p>Running import...</p>';

            fetch('$ajaxUrl')
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false;
                    if (data.success) {
                        log.innerHTML = '<p><strong>Success:</strong> ' + data.data.message + '</p>';
                    } else {
                        log.innerHTML = '<p><strong>Error:</strong> ' + (data.message || 'Unknown error') + '</p>';
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    log.innerHTML = '<p><strong>AJAX Error:</strong> ' + err.message + '</p>';
                });
        });
    }
});
JS;

        $doc->addScriptDeclaration($js);
    }
}
