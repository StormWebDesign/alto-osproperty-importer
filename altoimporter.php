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
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\MVC\Model\FormModel;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Form\Form;

use Joomla\Plugin\System\Altoimporter\Services\AltoApiService;

class PlgSystemAltoimporter extends CMSPlugin
{
    use MVCFactoryAwareTrait;

    protected $app;
    protected $autoloadLanguage = true;

    /**
     * Register our custom form field path.
     */
    public function onContentPrepareForm(Form $form, $data)
    {
        // Only for the plugin edit form
        if ($form->getName() === 'com_plugins.plugin') {
            $plugin = $form->getValue('element');
            if ($plugin === 'altoimporter') {
                FormHelper::addFieldPath(__DIR__ . '/fields');
            }
        }
    }

    /**
     * Scheduled Task entry point.
     */
    public function onAltoimporterTaskImport()
    {
        return $this->performImport();
    }

    /**
     * AJAX manual import entry point.
     */
    public function onAjaxAltoimporterDoImport()
    {
        try {
            $this->performImport();
            return new JsonResponse(['message' => Text::_('PLG_SYSTEM_ALTOIMPORTER_IMPORT_SUCCESS')]);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), true);
        }
    }

    /**
     * Core import logic.
     *
     * @return  bool
     * @throws  \RuntimeException
     */
    protected function performImport(): bool
    {
        $params   = $this->params;
        $apiKey   = trim($params->get('api_key', ''));
        $username = trim($params->get('username', ''));
        $password = trim($params->get('password', ''));
        $logLevel = $params->get('log_level', 'info');

        if (!$apiKey || !$username || !$password) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_ALTOIMPORTER_ERR_CREDENTIALS'));
        }

        // Instantiate and run the service
        $service = new AltoApiService($apiKey, $username, $password, $logLevel);
        $service->importAllProperties();

        return true;
    }

    /**
     * Inject the JS for the manual import button when editing plugin params.
     */
    public function onBeforeCompileHead()
    {
        // Only in admin & on our plugin edit view
        if (!$this->app->isClient('administrator')) {
            return;
        }

        $input = $this->app->input;
        if (
            $input->getCmd('option') !== 'com_plugins'
            || $input->getCmd('plugin') !== 'altoimporter'
        ) {
            return;
        }

        $doc     = Factory::getDocument();
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=altoimporter&group=system&format=json&task=doImport';

        $js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('btn-alto-manual-import');
    const log = document.getElementById('alto-import-log');
    if (!btn) return;

    btn.addEventListener('click', function () {
        btn.disabled = true;
        log.innerHTML = '<p>Running import...</p>';
        fetch('$ajaxUrl')
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (data.success) {
                    log.innerHTML = '<p><strong>Success:</strong> ' + data.data.message + '</p>';
                } else {
                    log.innerHTML = '<p><strong>Error:</strong> ' + (data.message||'Unknown error') + '</p>';
                }
            })
            .catch(err => {
                btn.disabled = false;
                log.innerHTML = '<p><strong>AJAX Error:</strong> ' + err.message + '</p>';
            });
    });
});
JS;
        $doc->addScriptDeclaration($js);
    }
}
