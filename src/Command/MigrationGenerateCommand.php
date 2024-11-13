<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function count;

class MigrationGenerateCommand extends Command
{

    private MigrationService $migrationService;

    public function __construct(
        MigrationService $migrationService
    )
    {
        parent::__construct();
        $this->migrationService = $migrationService;
    }

    public static function getDefaultName(): string
    {
        return 'migration:generate';
    }

    protected function configure(): void
    {
        $this->setDescription('Generate migration class');
        $this->addOption('empty-only', null, InputOption::VALUE_NONE, 'Skips SQL Diff and only produces empty migration file', null);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isEmptyOnly = (bool) $input->getOption('empty-only');
        if (!$isEmptyOnly) {
            $sqls = $this->migrationService->generateDiffSqls();

            if (count($sqls) === 0) {
                $output->writeln('<comment>No changes found, creating empty migration class...</comment>');
            }
        } else {
            $sqls = [];

            $output->writeln('<comment>Creating empty migration class...</comment>');
        }

        $file = $this->migrationService->generateMigrationFile($sqls);

        $output->writeln("<info>Migration version {$file->getVersion()} was generated</info>");
        return 0;
    }

}
