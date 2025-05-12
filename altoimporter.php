<?php
namespace Joomla\Plugin\System\Altoimporter;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Uri\Uri;

use Joomla\Plugin\System\Altoimporter\Services\AltoApiService;

class PlgSystemAltoimporter extends CMSPlugin
{
    protected $autoloadLanguage = true;

    public function onContentPrepareForm(\Joomla\CMS\Form\Form $form, $data)
    {
        if ($form->getName() === 'com_plugins.plugin')
        {
            FormHelper::addFieldPath(__DIR__ . '/fields');
        }
    }

    // Scheduled Task
    public function onAltoimporterTaskImport()
    {
        return $this->performImport();
    }

    // AJAX manual import
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
        $p = $this->params;
        if (!$p->get('api_key') || !$p->get('username') || !$p->get('password'))
        {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_ALTOIMPORTER_ERR_CREDENTIALS'));
        }

        $service = new AltoApiService(
            $p->get('api_key'),
            $p->get('username'),
            $p->get('password'),
            $p->get('log_level', 'info')
        );
        $service->importAllProperties();
        return true;
    }

    public function onBeforeCompileHead()
    {
        if (!$this->app->isClient('administrator'))
        {
            return;
        }
        $in = $this->app->input;
        if ($in->getCmd('option') !== 'com_plugins'
         || $in->getCmd('plugin') !== 'altoimporter')
        {
            return;
        }

        $doc     = Factory::getDocument();
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=altoimporter&group=system&format=json&task=doImport';

        $js = <<<JS
document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('btn-alto-manual-import'),
      log = document.getElementById('alto-import-log');
  if (!btn) return;
  btn.addEventListener('click', function(){
    btn.disabled = true;
    log.innerHTML = '<p>Import started…</p>';
    fetch('$ajaxUrl')
      .then(r => r.json())
      .then(data => {
        btn.disabled = false;
        log.innerHTML = data.success
          ? '<p><strong>Success:</strong> '+data.data.message+'</p>'
          : '<p><strong>Error:</strong> '+(data.message||'Unknown')+'</p>';
      })
      .catch(e => {
        btn.disabled = false;
        log.innerHTML = '<p><strong>AJAX Error:</strong> '+e.message+'</p>';
      });
  });
});
JS;
        $doc->addScriptDeclaration($js);
    }
}
