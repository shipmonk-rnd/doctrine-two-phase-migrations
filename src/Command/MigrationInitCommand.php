<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(self::NAME, description: 'Create migration table in database')]
class MigrationInitCommand extends Command
{

    use ConsoleLoggerFallbackTrait;

    public const NAME = 'migration:init';

    public function __construct(
        private readonly MigrationService $migrationService,
        private readonly ?LoggerInterface $logger = null,
    )
    {
        parent::__construct();
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $logger = $this->createLogger($output);

        $tableName = $this->migrationService->getConfig()->getMigrationTableName();

        $logger->info('Initializing migration table {tableName}', [
            'tableName' => $tableName,
        ]);

        $initialized = $this->migrationService->initializeMigrationTable();

        if ($initialized) {
            $logger->info('Migration table {tableName} created successfully', [
                'tableName' => $tableName,
            ]);
        } else {
            $logger->notice('Migration table {tableName} already exists', [
                'tableName' => $tableName,
            ]);
        }

        return 0;
    }

}
