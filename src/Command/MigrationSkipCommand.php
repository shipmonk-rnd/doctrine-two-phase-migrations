<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationRun;
use ShipMonk\Doctrine\Migration\MigrationServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function array_values;
use function count;

#[AsCommand(self::NAME, description: 'Mark all not executed migrations as executed in both phases')]
class MigrationSkipCommand extends Command
{

    use ConsoleLoggerFallbackTrait;
    use MigrationServiceRegistryAwareTrait;

    public const NAME = 'migration:skip';

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

        $logger->info('Starting migration skip');

        $skippedCount = 0;
        $skippedMigrations = [];

        foreach (MigrationPhase::cases() as $phase) {
            $executed = $migrationService->getExecutedVersions($phase);
            $prepared = $migrationService->getPreparedVersions();
            $toSkip = array_values(array_diff($prepared, $executed));

            if (count($toSkip) > 0) {
                $logger->info('Found {migrationSkipCount} migrations to skip in phase {migrationPhase}', [
                    'migrationPhase' => $phase->value,
                    'migrationSkipCount' => count($toSkip),
                    'migrationVersions' => $toSkip,
                ]);
            }

            foreach ($toSkip as $version) {
                $now = new DateTimeImmutable();
                $run = new MigrationRun($version, $phase, $now, $now);

                $migrationService->markMigrationExecuted($run);

                $logger->info('Migration {migrationVersion} phase {migrationPhase} skipped', [
                    'migrationVersion' => $version,
                    'migrationPhase' => $phase->value,
                    'migrationMarkedAt' => $now->format('Y-m-d H:i:s.u'),
                ]);

                $skippedMigrations[] = ['migrationVersion' => $version, 'migrationPhase' => $phase->value];
                $skippedCount++;
            }
        }

        if ($skippedCount === 0) {
            $logger->notice('No migrations to skip');
        } else {
            $logger->info('Migration skip completed, {migrationSkippedCount} skipped', [
                'migrationSkippedCount' => $skippedCount,
                'migrationSkipped' => $skippedMigrations,
            ]);
        }

        return 0;
    }

}
