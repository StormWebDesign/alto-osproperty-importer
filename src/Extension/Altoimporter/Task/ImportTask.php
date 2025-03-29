<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Altoimporter
 *
 * @copyright   Copyright (C) 2024
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

HTMLHelper::_('behavior.core');
HTMLHelper::_('formbehavior.chosen', 'select');
HTMLHelper::_('stylesheet', 'system/adminlist.css', ['version' => 'auto', 'relative' => true]);

$ajaxUrl = Route::_('index.php?option=com_ajax&plugin=altoimporter&group=system&format=json&task=runImport');
?>

<div class="container">
    <h1><?php echo Text::_('PLG_SYSTEM_ALTOIMPORTER_TITLE'); ?></h1>

    <p><?php echo Text::_('PLG_SYSTEM_ALTOIMPORTER_DESC'); ?></p>

    <button class="btn btn-success" id="import-button">
        <?php echo Text::_('PLG_SYSTEM_ALTOIMPORTER_RUN_IMPORT'); ?>
    </button>

    <div id="import-result" class="alert mt-3" style="display:none;"></div>

    <h2 class="mt-5"><?php echo Text::_('PLG_SYSTEM_ALTOIMPORTER_LOG_HEADING'); ?></h2>

    <pre class="bg-light p-3" style="max-height:400px; overflow-y:auto;">
<?php
    $logPath = JPATH_ROOT . '/logs/altoimporter.log';

    if (file_exists($logPath)) {
        echo htmlspecialchars(file_get_contents($logPath));
    } else {
        echo Text::_('PLG_SYSTEM_ALTOIMPORTER_NO_LOG');
    }
?>
    </pre>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('import-button').addEventListener('click', function () {
        const resultBox = document.getElementById('import-result');
        resultBox.classList.remove('alert-success', 'alert-danger');
        resultBox.innerText = '<?php echo Text::_('PLG_SYSTEM_ALTOIMPORTER_IMPORT_RUNNING'); ?>';
        resultBox.style.display = 'block';

        fetch('<?php echo $ajaxUrl; ?>')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultBox.classList.add('alert-success');
                    resultBox.innerText = data.message || '<?php echo Text::_('PLG_SYSTEM_ALTOIMPORTER_IMPORT_SUCCESS'); ?>';
                } else {
                    resultBox.classList.add('alert-danger');
                    resultBox.innerText = data.message || '<?php echo Text::_('PLG_SYSTEM_ALTOIMPORTER_IMPORT_FAILED'); ?>';
                }
            })
            .catch(error => {
                resultBox.classList.add('alert-danger');
                resultBox.innerText = '<?php echo Text::_('PLG_SYSTEM_ALTOIMPORTER_IMPORT_ERROR'); ?>: ' + error.message;
            });
    });
});
</script>
