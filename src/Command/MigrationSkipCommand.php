<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use DateTimeImmutable;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationRun;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;

#[AsCommand('migration:skip', description: 'Mark all not executed migrations as executed in both phases')]
class MigrationSkipCommand extends Command
{

    private MigrationService $migrationService;

    public function __construct(MigrationService $migrationService)
    {
        parent::__construct();
        $this->migrationService = $migrationService;
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $skipped = false;

        foreach (MigrationPhase::cases() as $phase) {
            $executed = $this->migrationService->getExecutedVersions($phase);
            $prepared = $this->migrationService->getPreparedVersions();

            foreach (array_diff($prepared, $executed) as $version) {
                $now = new DateTimeImmutable();
                $run = new MigrationRun($version, $phase, $now, $now);

                $this->migrationService->markMigrationExecuted($run);
                $output->writeln("Migration {$version} phase {$phase->value} skipped.");
                $skipped = true;
            }
        }

        if (!$skipped) {
            $output->writeln('No migration skipped.');
        }

        return 0;
    }

}
