use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;

// already present:
// use Joomla\CMS\Factory;
// use Joomla\CMS\Response\JsonResponse;
// etc.

public function onContentPrepareForm(Form $form, $data)
{
    if ($form->getName() !== 'com_plugins.plugin')
    {
        return;
    }

    $input = $this->app->input;
    if ($input->getString('plugin') !== 'altoimporter')
    {
        return;
    }

    // Load Joomla's core JS
    HTMLHelper::_('jquery.framework');

    // Inject manual import button
    $field = $form->getField('manual_import', 'params');
    if ($field)
    {
        $buttonHtml = '
            <button id="btn-alto-manual-import" class="btn btn-primary" type="button">
                ' . Text::_('PLG_SYSTEM_ALTOIMPORTER_MANUAL_IMPORT') . '
            </button>
            <div id="alto-import-log" style="margin-top: 10px;"></div>
        ';

        $field->setAttribute('description', $buttonHtml);
    }
}

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
    )
    {
        return;
    }

    $doc = Factory::getDocument();
    $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=altoimporter&group=system&format=json&task=doImport';

    $js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('btn-alto-manual-import');
    const log = document.getElementById('alto-import-log');

    if (!btn) return;

    btn.addEventListener('click', function () {
        btn.disabled = true;
        log.innerHTML = '<p>Import started...</p>';

        fetch('$ajaxUrl')
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                if (data.success) {
                    log.innerHTML = '<p><strong>Success:</strong> ' + data.data.message + '</p>';
                } else {
                    log.innerHTML = '<p><strong>Error:</strong> ' + (data.message || 'Unknown error') + '</p>';
                }
            })
            .catch(error => {
                btn.disabled = false;
                log.innerHTML = '<p><strong>AJAX Error:</strong> ' + error.message + '</p>';
            });
    });
});
JS;

    $doc->addScriptDeclaration($js);
}
