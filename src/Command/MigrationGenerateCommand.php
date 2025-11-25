<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function count;

#[AsCommand('migration:generate', description: 'Generate migration class')]
class MigrationGenerateCommand extends Command
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
        $this->logger->info('Starting migration generation');

        $sqls = $this->migrationService->generateDiffSqls();
        $sqlCount = count($sqls);

        if ($sqlCount === 0) {
            $this->logger->notice('No schema changes found, creating empty migration class');
        } else {
            $this->logger->info('Schema changes detected', [
                'sqlCount' => $sqlCount,
                'sqls' => $sqls,
            ]);
        }

        $file = $this->migrationService->generateMigrationFile($sqls);

        $this->logger->info('Migration generated successfully', [
            'version' => $file->getVersion(),
            'filePath' => $file->getFilePath(),
            'sqlCount' => $sqlCount,
            'isEmpty' => $sqlCount === 0,
        ]);

        return 0;
    }

}
