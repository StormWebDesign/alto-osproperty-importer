<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;

?>
<div class="ospropertyimporter-admin">
    <h2><?php echo Text::_('PLG_SYSTEM_OSPROPERTYIMPORTER_MANUAL_TITLE'); ?></h2>
    <p><?php echo Text::_('PLG_SYSTEM_OSPROPERTYIMPORTER_MANUAL_DESC'); ?></p>

    <form method="post" action="<?php echo Route::_('index.php?option=com_ajax&plugin=ospropertyimporter&format=json'); ?>">
        <button type="submit" class="btn btn-primary">
            <?php echo Text::_('PLG_SYSTEM_OSPROPERTYIMPORTER_MANUAL_RUN'); ?>
        </button>
    </form>
</div>
