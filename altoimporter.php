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
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;

use Joomla\Plugin\System\Altoimporter\Service\AltoApiService;

final class PlgSystemAltoimporter extends CMSPlugin
{
    use MVCFactoryAwareTrait;

    protected CMSApplication $app;
    protected bool $autoloadLanguage = true;

    /**
     * Adds the custom field path for our manual import button
     */
    public function onContentPrepareForm(Form $form, $data): void
    {
        if ($form->getName() === 'com_plugins.plugin')
        {
            FormHelper::addFieldPath(__DIR__ . '/src/Field');
        }
    }

    /**
     * Injects JS when viewing this plugin in admin
     */
    public function onBeforeCompileHead(): void
    {
        if (!$this->app->isClient('administrator'))
        {
            return;
        }

        $input = $this->app->getInput();

        if (
            $input->getCmd('option') === 'com_plugins'
            && $input->getCmd('plugin') === 'altoimporter'
        )
        {
            /** @var HtmlDocument $doc */
            $doc = Factory::getDocument();
            $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=altoimporter&group=system&format=json&task=doImport';

            $script = <<<JS
window.Joomla = window.Joomla || {};
Joomla.altoImportManualRun = function () {
    const btn = document.querySelector('[name="jform[params][manual_import]"]');
    btn.disabled = true;

    fetch('$ajaxUrl')
        .then(res => res.json())
        .then(data => {
            alert(data.success ? 'Import Success' : 'Import Failed: ' + data.message);
            btn.disabled = false;
        })
        .catch(err => {
            alert('Error: ' + err.message);
            btn.disabled = false;
        });
};
JS;

            $doc->addScriptDeclaration($script);
        }
    }

    /**
     * AJAX trigger from the manual import button
     */
    public function onAjaxAltoimporterDoImport(): JsonResponse
    {
        try
        {
            $this->runImport();
            return new JsonResponse(['message' => Text::_('PLG_SYSTEM_ALTOIMPORTER_IMPORT_SUCCESS')]);
        }
        catch (\Throwable $e)
        {
            return new JsonResponse($e->getMessage(), true);
        }
    }

    /**
     * Scheduled Task entry point
     */
    public function onAltoimporterTaskImport(): bool
    {
        return $this->runImport();
    }

    /**
     * Shared logic for both manual and scheduled import
     */
    private function runImport(): bool
    {
        $params = $this->params;

        $apiKey   = trim($params->get('api_key'));
        $username = trim($params->get('username'));
        $password = trim($params->get('password'));

        if (!$apiKey || !$username || !$password)
        {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_ALTOIMPORTER_ERR_CREDENTIALS'));
        }

        $service = new AltoApiService($apiKey, $username, $password);
        $service->importAllProperties();

        return true;
    }
}
