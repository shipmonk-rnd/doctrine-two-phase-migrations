<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function count;
use function implode;

class MigrationCheckCommand extends Command
{

    private EntityManagerInterface $entityManager;

    private MigrationService $migrationService;

    public function __construct(
        EntityManagerInterface $entityManager,
        MigrationService $migrationService,
    )
    {
        parent::__construct();
        $this->entityManager = $entityManager;
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
        $this->checkMigrationsExecuted($output);
        $this->checkEntitiesSyncedWithDatabase($output);

        return 0;
    }

    private function checkEntitiesSyncedWithDatabase(OutputInterface $output): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $updates = $schemaTool->getUpdateSchemaSql($metadata, !$this->migrationService->shouldIncludeDropTableInDatabaseSync());

        if (count($updates) !== 0) {
            $output->writeln("<comment>Database is not synced with entities, missing updates:\n > " . implode("\n > ", $updates) . '</comment>');
        } else {
            $output->writeln('<info>Database is synced with entities, no migration needed.</info>');
        }
    }

    private function checkMigrationsExecuted(OutputInterface $output): void
    {
        $migrationsDir = $this->migrationService->getMigrationsDir();

        foreach ([MigrationPhase::BEFORE, MigrationPhase::AFTER] as $phase) {
            $executed = $this->migrationService->getExecutedVersions($phase);
            $prepared = $this->migrationService->getPreparedVersions();

            $toBeExecuted = array_diff($prepared, $executed);
            $executedNotPresent = array_diff($executed, $prepared);

            if (count($executedNotPresent) > 0) {
                $output->writeln("<error>Phase $phase has executed migrations not present in {$migrationsDir}: " . implode(', ', $executedNotPresent) . '</error>');
            }

            if (count($toBeExecuted) > 0) {
                $output->writeln("<comment>Phase $phase not fully executed, awaiting migrations:\n > " . implode("\n > ", $toBeExecuted) . '</comment>');
            }

            if (count($executedNotPresent) === 0 && count($toBeExecuted) === 0) {
                $output->writeln("<info>Phase $phase fully executed, no awaiting migrations</info>");
            }
        }
    }

}
