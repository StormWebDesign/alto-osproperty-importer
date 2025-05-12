<?php
namespace Joomla\Plugin\System\Altoimporter\Extension\Altoimporter\Task;

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Task\TaskInterface;
use Joomla\CMS\Language\Text;
use Joomla\Plugin\System\Altoimporter\PlgSystemAltoimporter;

class ImportTask implements TaskInterface
{
    public function execute(string $taskName, array $arguments = []): bool
    {
        $plugin = PluginHelper::getPlugin('system','altoimporter');
        $params = new \Joomla\Registry\Registry($plugin->params);
        $plg    = new PlgSystemAltoimporter(null, ['params' => $params]);
        return (bool) $plg->onAltoimporterTaskImport();
    }

    public function getTitle(): string
    {
        return Text::_('PLG_SYSTEM_ALTOIMPORTER_TASK_IMPORT_TITLE');
    }

    public function getDescription(): string
    {
        return Text::_('PLG_SYSTEM_ALTOIMPORTER_TASK_IMPORT_DESC');
    }
}
