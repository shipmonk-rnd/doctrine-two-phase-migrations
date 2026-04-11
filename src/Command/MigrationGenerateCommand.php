<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function count;

#[AsCommand(self::NAME, description: 'Generate migration class')]
class MigrationGenerateCommand extends Command
{

    use ConsoleLoggerFallbackTrait;
    use MigrationServiceRegistryAwareTrait;

    public const NAME = 'migration:generate';

    public function __construct(
        private readonly MigrationServiceRegistry $migrationServiceRegistry,
        private readonly ?LoggerInterface $logger = null,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addNamespaceOption();
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $logger = $this->createLogger($output);
        $migrationService = $this->getMigrationService($input);

        $logger->info('Starting migration generation');

        $sqls = $migrationService->generateDiffSqls();
        $sqlCount = count($sqls);

        if ($sqlCount === 0) {
            $logger->notice('No schema changes found, creating empty migration class');
        } else {
            $logger->info('{migrationSqlCount} schema changes detected', [
                'migrationSqlCount' => $sqlCount,
                'migrationSqls' => $sqls,
            ]);
        }

        $file = $migrationService->generateMigrationFile($sqls);

        $logger->info('Migration version {migrationVersion} generated successfully', [
            'migrationVersion' => $file->version,
            'migrationFilePath' => $file->filePath,
            'migrationSqlCount' => $sqlCount,
            'migrationIsEmpty' => $sqlCount === 0,
        ]);

        return 0;
    }

}
