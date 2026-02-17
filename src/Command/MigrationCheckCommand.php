<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function count;
use function implode;
use const PHP_EOL;

#[AsCommand(self::NAME, description: 'Check if entities are in sync with database and if migrations were executed')]
class MigrationCheckCommand extends Command
{

    public const NAME = 'migration:check';

    private const EXIT_ENTITIES_NOT_SYNCED = 4;
    private const EXIT_UNKNOWN_MIGRATION = 2;
    private const EXIT_AWAITING_MIGRATION = 1;
    private const EXIT_OK = 0;

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
        $exitCode = self::EXIT_OK;
        $exitCode |= $this->checkMigrationsExecuted($output);
        $exitCode |= $this->checkEntitiesSyncedWithDatabase($output);

        return $exitCode;
    }

    private function checkEntitiesSyncedWithDatabase(OutputInterface $output): int
    {
        $updates = $this->migrationService->generateDiffSqls();

        if (count($updates) !== 0) {
            $output->writeln('<comment>Database is not synced with entities, missing updates:' . PHP_EOL . ' > ' . implode(PHP_EOL . ' > ', $updates) . '</comment>');
            return self::EXIT_ENTITIES_NOT_SYNCED;
        }

        $output->writeln('<info>Database is synced with entities, no migration needed.</info>');
        return self::EXIT_OK;
    }

    private function checkMigrationsExecuted(OutputInterface $output): int
    {
        $exitCode = self::EXIT_OK;
        $migrationsDir = $this->migrationService->getConfig()->getMigrationsDirectory();

        foreach (MigrationPhase::cases() as $phase) {
            $executed = $this->migrationService->getExecutedVersions($phase);
            $prepared = $this->migrationService->getPreparedVersions();

            $toBeExecuted = array_diff($prepared, $executed);
            $executedNotPresent = array_diff($executed, $prepared);

            if (count($executedNotPresent) > 0) {
                $exitCode |= self::EXIT_UNKNOWN_MIGRATION;
                $output->writeln("<error>Phase $phase->value has executed migrations not present in {$migrationsDir}: " . implode(', ', $executedNotPresent) . '</error>');
            }

            if (count($toBeExecuted) > 0) {
                $exitCode |= self::EXIT_AWAITING_MIGRATION;
                $output->writeln("<comment>Phase $phase->value not fully executed, awaiting migrations:" . PHP_EOL . ' > ' . implode(PHP_EOL . ' > ', $toBeExecuted) . '</comment>');
            }

            if (count($executedNotPresent) === 0 && count($toBeExecuted) === 0) {
                $output->writeln("<info>Phase $phase->value fully executed, no awaiting migrations</info>");
            }
        }

        return $exitCode;
    }

}
