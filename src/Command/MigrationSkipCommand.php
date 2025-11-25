<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationRun;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function array_values;
use function count;

#[AsCommand('migration:skip', description: 'Mark all not executed migrations as executed in both phases')]
class MigrationSkipCommand extends Command
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
        $this->logger->info('Starting migration skip');

        $skippedCount = 0;
        $skippedMigrations = [];

        foreach (MigrationPhase::cases() as $phase) {
            $executed = $this->migrationService->getExecutedVersions($phase);
            $prepared = $this->migrationService->getPreparedVersions();
            $toSkip = array_values(array_diff($prepared, $executed));

            if (count($toSkip) > 0) {
                $this->logger->info('Found migrations to skip', [
                    'phase' => $phase->value,
                    'count' => count($toSkip),
                    'versions' => $toSkip,
                ]);
            }

            foreach ($toSkip as $version) {
                $now = new DateTimeImmutable();
                $run = new MigrationRun($version, $phase, $now, $now);

                $this->migrationService->markMigrationExecuted($run);

                $this->logger->info('Migration skipped', [
                    'version' => $version,
                    'phase' => $phase->value,
                    'markedAt' => $now->format('Y-m-d H:i:s.u'),
                ]);

                $skippedMigrations[] = ['version' => $version, 'phase' => $phase->value];
                $skippedCount++;
            }
        }

        if ($skippedCount === 0) {
            $this->logger->notice('No migrations to skip');
        } else {
            $this->logger->info('Migration skip completed', [
                'skippedCount' => $skippedCount,
                'skippedMigrations' => $skippedMigrations,
            ]);
        }

        return 0;
    }

}
