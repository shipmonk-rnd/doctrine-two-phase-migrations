<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use LogicException;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\MigrationServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_map;
use function count;
use function in_array;
use function is_string;
use function round;

#[AsCommand(self::NAME, description: 'Run all not executed migrations with specified phase')]
class MigrationRunCommand extends Command
{

    use ConsoleLoggerFallbackTrait;
    use MigrationServiceRegistryAwareTrait;

    public const NAME = 'migration:run';

    public const ARGUMENT_PHASE = 'phase';
    public const PHASE_BOTH = 'both';

    public function __construct(
        private readonly MigrationServiceRegistry $migrationServiceRegistry,
        private readonly ?LoggerInterface $logger = null,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            self::ARGUMENT_PHASE,
            InputArgument::REQUIRED,
            MigrationPhase::BEFORE->value . '|' . MigrationPhase::AFTER->value . '|' . self::PHASE_BOTH,
        );
        $this->addNamespaceOption();
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $phaseArgument = $input->getArgument(self::ARGUMENT_PHASE);

        if (!is_string($phaseArgument)) {
            throw new LogicException('Can never happen for required non-array argument');
        }

        $logger = $this->createLogger($output);
        $migrationService = $this->getMigrationService($input);

        $phases = $this->getPhasesToRun($phaseArgument);

        $logger->info('Starting migration execution (phase {migrationPhaseArgument})', [
            'migrationPhaseArgument' => $phaseArgument,
            'migrationPhases' => array_map(static fn (MigrationPhase $phase): string => $phase->value, $phases),
        ]);

        $migratedSomething = $this->executeMigrations($logger, $migrationService, $phases);

        if (!$migratedSomething) {
            $logger->notice('No migrations to execute (phase {migrationPhaseArgument})', [
                'migrationPhaseArgument' => $phaseArgument,
            ]);
        } else {
            $logger->info('Migration execution completed (phase {migrationPhaseArgument})', [
                'migrationPhaseArgument' => $phaseArgument,
            ]);
        }

        return 0;
    }

    /**
     * @param list<MigrationPhase> $phases
     */
    private function executeMigrations(
        LoggerInterface $logger,
        MigrationService $migrationService,
        array $phases,
    ): bool
    {
        $executed = [];

        if (in_array(MigrationPhase::BEFORE, $phases, true)) {
            $executed[MigrationPhase::BEFORE->value] = $migrationService->getExecutedVersions(MigrationPhase::BEFORE);
        }

        if (in_array(MigrationPhase::AFTER, $phases, true)) {
            $executed[MigrationPhase::AFTER->value] = $migrationService->getExecutedVersions(MigrationPhase::AFTER);
        }

        $preparedVersions = $migrationService->getPreparedVersions();
        $migratedSomething = false;

        $pendingMigrations = [];

        foreach ($preparedVersions as $version) {
            foreach ($phases as $phase) {
                if (!isset($executed[$phase->value][$version])) {
                    $pendingMigrations[] = ['migrationVersion' => $version, 'migrationPhase' => $phase->value];
                }
            }
        }

        if (count($pendingMigrations) > 0) {
            $logger->info('{migrationPendingCount} pending migrations found', [
                'migrationPendingCount' => count($pendingMigrations),
                'migrationPending' => $pendingMigrations,
            ]);
        }

        foreach ($preparedVersions as $version) {
            foreach ($phases as $phase) {
                if (isset($executed[$phase->value][$version])) {
                    continue;
                }

                $this->executeMigration($logger, $migrationService, $version, $phase);
                $migratedSomething = true;
            }
        }

        return $migratedSomething;
    }

    private function executeMigration(
        LoggerInterface $logger,
        MigrationService $migrationService,
        string $version,
        MigrationPhase $phase,
    ): void
    {
        $logger->info('Executing migration {migrationVersion} phase {migrationPhase}', [
            'migrationVersion' => $version,
            'migrationPhase' => $phase->value,
        ]);

        $run = $migrationService->executeMigration($version, $phase);

        $logger->info('Migration {migrationVersion} phase {migrationPhase} executed successfully, {migrationDurationSeconds} s elapsed', [
            'migrationVersion' => $version,
            'migrationPhase' => $phase->value,
            'migrationDurationSeconds' => round($run->getDuration(), 3),
            'migrationStartedAt' => $run->getStartedAt()->format('Y-m-d H:i:s.u'),
            'migrationFinishedAt' => $run->getFinishedAt()->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * @return list<MigrationPhase>
     */
    private function getPhasesToRun(string $phaseArgument): array
    {
        if ($phaseArgument === MigrationPhase::BEFORE->value) {
            return [MigrationPhase::BEFORE];
        }

        if ($phaseArgument === MigrationPhase::AFTER->value) {
            return [MigrationPhase::AFTER];
        }

        if ($phaseArgument === self::PHASE_BOTH) {
            return [MigrationPhase::BEFORE, MigrationPhase::AFTER];
        }

        throw new LogicException('Unexpected phase argument');
    }

}
