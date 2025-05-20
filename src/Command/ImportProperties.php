<?php
namespace Joomla\Plugin\Osproperty\Altoimport\Command;

use Joomla\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Joomla\CMS\Factory;

class ImportProperties extends Command
{
    protected static $defaultName = 'altoimport:run';

    protected function configure()
    {
        $this->setDescription('Sync properties from Alto API')
             ->addOption('full', null, InputOption::VALUE_NONE, 'Run full import')
             ->addOption('delta', null, InputOption::VALUE_NONE, 'Run delta import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app    = Factory::getApplication();
        $plugin = $app->getPlugin('osproperty', 'altoimport');
        $params = json_decode($plugin->params);
        $client = new \Joomla\Plugin\Osproperty\Altoimport\Service\AltoClient($params);
        $sync   = new \Joomla\Plugin\Osproperty\Altoimport\Service\PropertySync($client);

        if ($input->getOption('full')) {
            $sync->syncAll();
        } else {
            $sync->syncDelta();
        }

        return self::SUCCESS;
    }
}