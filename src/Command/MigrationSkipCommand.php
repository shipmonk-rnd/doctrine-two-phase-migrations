<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use DateTimeImmutable;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationRun;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;

class MigrationSkipCommand extends Command
{

    private MigrationService $migrationService;

    public function __construct(MigrationService $migrationService)
    {
        parent::__construct();
        $this->migrationService = $migrationService;
    }

    public static function getDefaultName(): string
    {
        return 'migration:skip';
    }

    protected function configure(): void
    {
        $this->setDescription('Mark all not executed migrations as executed in both phases');
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $skipped = false;

        foreach ([MigrationPhase::BEFORE, MigrationPhase::AFTER] as $phase) {
            $executed = $this->migrationService->getExecutedVersions($phase);
            $prepared = $this->migrationService->getPreparedVersions();

            foreach (array_diff($prepared, $executed) as $version) {
                $now = new DateTimeImmutable();
                $run = new MigrationRun($version, $phase, $now, $now);

                $this->migrationService->markMigrationExecuted($run);
                $output->writeln("Migration {$version} phase {$phase} skipped.");
                $skipped = true;
            }
        }

        if (!$skipped) {
            $output->writeln('No migration skipped.');
        }

        return 0;
    }

}
