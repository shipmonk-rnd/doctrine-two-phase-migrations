<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function count;
use function implode;

class MigrationCheckCommand extends Command
{

    private const EXIT_ENTITIES_NOT_SYNCED = 4;
    private const EXIT_UNKNOWN_MIGRATION = 2;
    private const EXIT_AWAITING_MIGRATION = 1;
    private const EXIT_OK = 0;

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
            ->setName('migration:check')
            ->setDescription('Check if entities are in sync with database and if migrations were executed');
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = self::EXIT_OK;
        $exitCode |= $this->checkMigrationsExecuted($output);
        $exitCode |= $this->checkEntitiesSyncedWithDatabase($output);

        return $exitCode;
    }

    private function checkEntitiesSyncedWithDatabase(OutputInterface $output): int
    {
        $updates = $this->migrationService->generateDiffSqls();

        if (count($updates) !== 0) {
            $output->writeln("<comment>Database is not synced with entities, missing updates:\n > " . implode("\n > ", $updates) . '</comment>');
            return self::EXIT_ENTITIES_NOT_SYNCED;
        }

        $output->writeln('<info>Database is synced with entities, no migration needed.</info>');
        return self::EXIT_OK;
    }

    private function checkMigrationsExecuted(OutputInterface $output): int
    {
        $exitCode = self::EXIT_OK;
        $migrationsDir = $this->migrationService->getMigrationsDir();

        foreach ([MigrationPhase::BEFORE, MigrationPhase::AFTER] as $phase) {
            $executed = $this->migrationService->getExecutedVersions($phase);
            $prepared = $this->migrationService->getPreparedVersions();

            $toBeExecuted = array_diff($prepared, $executed);
            $executedNotPresent = array_diff($executed, $prepared);

            if (count($executedNotPresent) > 0) {
                $exitCode |= self::EXIT_UNKNOWN_MIGRATION;
                $output->writeln("<error>Phase $phase has executed migrations not present in {$migrationsDir}: " . implode(', ', $executedNotPresent) . '</error>');
            }

            if (count($toBeExecuted) > 0) {
                $exitCode |= self::EXIT_AWAITING_MIGRATION;
                $output->writeln("<comment>Phase $phase not fully executed, awaiting migrations:\n > " . implode("\n > ", $toBeExecuted) . '</comment>');
            }

            if (count($executedNotPresent) === 0 && count($toBeExecuted) === 0) {
                $output->writeln("<info>Phase $phase fully executed, no awaiting migrations</info>");
            }
        }

        return $exitCode;
    }

}
