<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('migration:init', description: 'Create migration table in database')]
class MigrationInitCommand extends Command
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
        $output->write('<comment>Creating migration table... </comment>');
        $initialized = $this->migrationService->initializeMigrationTable();
        $output->writeln($initialized ? '<info>done.</info>' : '<comment>already exists</comment>');

        return 0;
    }

}
