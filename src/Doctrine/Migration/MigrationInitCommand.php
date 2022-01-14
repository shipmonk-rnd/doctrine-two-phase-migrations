<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationInitCommand extends Command
{

    private MigrationService $migrationService;

    public function __construct(
        MigrationService $migrationService
    )
    {
        parent::__construct();

        $this->migrationService = $migrationService;
    }

    protected function configure(): void
    {
        $this
            ->setName('migration:init')
            ->setDescription('Create migration table in database');
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $output->write('<comment>Creating migration table... </comment>');
        $initialized = $this->migrationService->initializeMigrationTable();
        $output->writeln($initialized ? '<info>done.</info>' : '<comment>already exists</comment>');

        return 0;
    }

}
