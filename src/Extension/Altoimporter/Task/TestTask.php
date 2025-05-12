<?php
namespace Joomla\Plugin\System\Altoimporter\Extension\Altoimporter\Task;

use Joomla\CMS\Task\TaskInterface;
use Joomla\CMS\Language\Text;

class TestTask implements TaskInterface
{
    public function execute(string $taskName, array $arguments = []): bool
    {
        \Joomla\CMS\Factory::getApplication()->enqueueMessage('Test task executed!', 'info');
        return true;
    }

    public function getTitle(): string
    {
        return Text::_('PLG_SYSTEM_ALTOIMPORTER_TASK_TEST_TITLE');
    }

    public function getDescription(): string
    {
        return Text::_('PLG_SYSTEM_ALTOIMPORTER_TASK_TEST_DESC');
    }
}