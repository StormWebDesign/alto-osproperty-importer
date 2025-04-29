<?php
namespace Joomla\Plugin\System\Altoimporter;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Form\Form;
use Joomla\Plugin\System\Altoimporter\Services\AltoApiService;

class PlgSystemAltoimporter extends CMSPlugin
{
    protected $app;
    protected $autoloadLanguage = true;

    public function onContentPrepareForm(Form $form, $data)
    {
        if ($form->getName() === 'com_plugins.plugin')
        {
            FormHelper::addFieldPath(__DIR__ . '/fields');
        }
    }

    public function onAltoimporterTaskImport()
    {
        return $this->performImport();
    }

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

    protected function performImport(): bool
    {
        $params = $this->params;
        $apiKey = trim($params->get('api_key', ''));
        $username = trim($params->get('username', ''));
        $password = trim($params->get('password', ''));
        $logLevel = $params->get('log_level', 'info');

        if (!$apiKey || !$username || !$password)
        {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_ALTOIMPORTER_ERR_CREDENTIALS'));
        }

        $service = new AltoApiService($apiKey, $username, $password, $logLevel);
        $service->importAllProperties();

        return true;
    }
}
