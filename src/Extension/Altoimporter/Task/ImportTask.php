<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Altoimporter
 * @copyright   Copyright (C) 2025 Storm Web Design
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Plugin\System\Altoimporter\Extension\Altoimporter\Task;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Scheduler\Administrator\Task\TaskInterface;
use Joomla\Component\Scheduler\Administrator\Task\TaskResult;

class ImportTask implements TaskInterface
{
    /**
     * Execute the scheduled import task.
     *
     * @param   \Joomla\Component\Scheduler\Administrator\Task\TaskContext $context
     * @return  TaskResult
     */
    public function execute($context): TaskResult
    {
        // Load the plugin if not already loaded
        PluginHelper::importPlugin('system', 'altoimporter');

        // Trigger the task event
        $results = \JFactory::getApplication()->triggerEvent('onAltoimporterTaskImport');

        $success = is_array($results) && in_array(true, $results, true);

        return $success
            ? TaskResult::success(Text::_('PLG_SYSTEM_ALTOIMPORTER_TASK_IMPORT_SUCCESS'))
            : TaskResult::failure(Text::_('PLG_SYSTEM_ALTOIMPORTER_TASK_IMPORT_FAILED'));
    }
}
