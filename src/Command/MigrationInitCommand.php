<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('migration:init', description: 'Create migration table in database')]
class MigrationInitCommand extends Command
{

    public function __construct(
        private readonly MigrationService $migrationService,
        private readonly LoggerInterface $logger,
    )
    {
        parent::__construct();
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $tableName = $this->migrationService->getConfig()->getMigrationTableName();

        $this->logger->info('Initializing migration table', [
            'tableName' => $tableName,
        ]);

        $initialized = $this->migrationService->initializeMigrationTable();

        if ($initialized) {
            $this->logger->info('Migration table created successfully', [
                'tableName' => $tableName,
            ]);
        } else {
            $this->logger->notice('Migration table already exists', [
                'tableName' => $tableName,
            ]);
        }

        return 0;
    }

}
