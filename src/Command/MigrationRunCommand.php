<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use LogicException;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_map;
use function count;
use function in_array;
use function is_string;

#[AsCommand('migration:run', description: 'Run all not executed migrations with specified phase')]
class MigrationRunCommand extends Command
{

    public const ARGUMENT_PHASE = 'phase';
    public const PHASE_BOTH = 'both';

    public function __construct(
        private readonly MigrationService $migrationService,
        private readonly LoggerInterface $logger,
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

        $phases = $this->getPhasesToRun($phaseArgument);

        $this->logger->info('Starting migration execution', [
            'phaseArgument' => $phaseArgument,
            'phases' => array_map(static fn (MigrationPhase $phase): string => $phase->value, $phases),
        ]);

        $migratedSomething = $this->executeMigrations($phases);

        if (!$migratedSomething) {
            $this->logger->notice('No migrations to execute', [
                'phaseArgument' => $phaseArgument,
            ]);
        } else {
            $this->logger->info('Migration execution completed', [
                'phaseArgument' => $phaseArgument,
            ]);
        }

        return 0;
    }

    /**
     * @param list<MigrationPhase> $phases
     */
    private function executeMigrations(array $phases): bool
    {
        $executed = [];

        if (in_array(MigrationPhase::BEFORE, $phases, true)) {
            $executed[MigrationPhase::BEFORE->value] = $this->migrationService->getExecutedVersions(MigrationPhase::BEFORE);
        }

        if (in_array(MigrationPhase::AFTER, $phases, true)) {
            $executed[MigrationPhase::AFTER->value] = $this->migrationService->getExecutedVersions(MigrationPhase::AFTER);
        }

        $preparedVersions = $this->migrationService->getPreparedVersions();
        $migratedSomething = false;

        $pendingMigrations = [];

        foreach ($preparedVersions as $version) {
            foreach ($phases as $phase) {
                if (!isset($executed[$phase->value][$version])) {
                    $pendingMigrations[] = ['version' => $version, 'phase' => $phase->value];
                }
            }
        }

        if (count($pendingMigrations) > 0) {
            $this->logger->info('Pending migrations found', [
                'count' => count($pendingMigrations),
                'migrations' => $pendingMigrations,
            ]);
        }

        foreach ($preparedVersions as $version) {
            foreach ($phases as $phase) {
                if (isset($executed[$phase->value][$version])) {
                    continue;
                }

                $this->executeMigration($version, $phase);
                $migratedSomething = true;
            }
        }

        return $migratedSomething;
    }

    private function executeMigration(
        string $version,
        MigrationPhase $phase,
    ): void
    {
        $this->logger->info('Executing migration', [
            'version' => $version,
            'phase' => $phase->value,
        ]);

        $run = $this->migrationService->executeMigration($version, $phase);

        $this->logger->info('Migration executed successfully', [
            'version' => $version,
            'phase' => $phase->value,
            'durationSeconds' => $run->getDuration(),
            'startedAt' => $run->getStartedAt()->format('Y-m-d H:i:s.u'),
            'finishedAt' => $run->getFinishedAt()->format('Y-m-d H:i:s.u'),
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
