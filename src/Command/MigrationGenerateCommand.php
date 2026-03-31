<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use function count;

#[AsCommand(self::NAME, description: 'Generate migration class')]
class MigrationGenerateCommand extends Command
{

    public const NAME = 'migration:generate';

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
        $logger = $this->logger ?? new ConsoleLogger($output, [
            LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL,
            LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL,
        ]);

        $logger->info('Starting migration generation');

        $sqls = $this->migrationService->generateDiffSqls();
        $sqlCount = count($sqls);

        if ($sqlCount === 0) {
            $logger->notice('No schema changes found, creating empty migration class');
        } else {
            $logger->info('{sqlCount} schema changes detected', [
                'sqlCount' => $sqlCount,
                'sqls' => $sqls,
            ]);
        }

        $file = $this->migrationService->generateMigrationFile($sqls);

        $logger->info('Migration version {version} generated successfully', [
            'version' => $file->version,
            'filePath' => $file->filePath,
            'sqlCount' => $sqlCount,
            'isEmpty' => $sqlCount === 0,
        ]);

        return 0;
    }

}
