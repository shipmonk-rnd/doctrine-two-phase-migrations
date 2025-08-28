<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function count;

#[AsCommand('migration:generate', description: 'Generate migration class')]
class MigrationGenerateCommand extends Command
{

    private MigrationService $migrationService;

    public function __construct(
        MigrationService $migrationService,
    )
    {
        parent::__construct();
        $this->migrationService = $migrationService;
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $sqls = $this->migrationService->generateDiffSqls();

        if (count($sqls) === 0) {
            $output->writeln('<comment>No changes found, creating empty migration class...</comment>');
        }

        $file = $this->migrationService->generateMigrationFile($sqls);

        $output->writeln("<info>Migration version {$file->getVersion()} was generated</info>");
        return 0;
    }

}
