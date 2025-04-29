<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Altoimporter
 * @copyright   Copyright (C) 2024
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Language\Text;

class JFormFieldImportbutton extends FormField
{
    /**
     * The form field type.
     *
     * Must match the <field type="importbutton" /> in the XML.
     *
     * @var  string
     */
    protected $type = 'importbutton';

    /**
     * Method to get the form field input markup.
     *
     * @return  string
     */
    protected function getInput()
    {
        // Load jQuery
        HTMLHelper::_('jquery.framework');

        $buttonId = 'btn-alto-manual-import';
        $logId    = 'alto-import-log';

        // AJAX endpoint
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=altoimporter&group=system&format=json&task=doImport';

        // Inline JS
        $script = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('$buttonId');
    const log = document.getElementById('$logId');

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
        Factory::getDocument()->addScriptDeclaration($script);

        // Button + log container
        return '
            <button class="btn btn-primary" type="button" id="' . $buttonId . '">
                ' . Text::_('PLG_SYSTEM_ALTOIMPORTER_MANUAL_IMPORT') . '
            </button>
            <div id="' . $logId . '" style="margin-top:10px;"></div>
        ';
    }
}
