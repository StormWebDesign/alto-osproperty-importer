<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Ospropertyimporter
 */

\defined('_JEXEC') or die;

use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\CMS\Extension\PluginInterface;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                return new \Joomla\Plugin\System\Ospropertyimporter\Ospropertyimporter(
                    $container->get('plugin.manager')->getPlugin('system', 'ospropertyimporter'),
                    $container
                );
            }
        );
    }
};
