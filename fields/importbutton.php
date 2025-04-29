<?php
defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

class JFormFieldImportbutton extends FormField
{
    protected $type = 'Importbutton';

    protected function getInput()
    {
        HTMLHelper::_('jquery.framework');

        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=altoimporter&group=system&format=json&task=doImport';

        $script = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('btn-alto-import');
    if (!btn) return;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        fetch('$ajaxUrl')
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                alert(data.success ? 'Success: ' + data.data.message : 'Error: ' + data.message);
            })
            .catch(err => {
                btn.disabled = false;
                alert('AJAX error: ' + err.message);
            });
    });
});
JS;

        Factory::getDocument()->addScriptDeclaration($script);

        return '<button id="btn-alto-import" class="btn btn-primary" type="button">' . Text::_('PLG_SYSTEM_ALTOIMPORTER_MANUAL_IMPORT') . '</button>';
    }
}
