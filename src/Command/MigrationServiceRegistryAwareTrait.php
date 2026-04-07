<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use function is_string;

trait MigrationServiceRegistryAwareTrait
{

    private function addNamespaceOption(): void
    {
        $this->addOption(
            'namespace',
            null,
            InputOption::VALUE_REQUIRED,
            'Migration namespace to use (required when multiple namespaces are configured)',
        );
    }

    private function getMigrationService(InputInterface $input): MigrationService
    {
        $namespace = $input->getOption('namespace');

        return $this->migrationServiceRegistry->get(
            is_string($namespace) ? $namespace : null,
        );
    }

}
