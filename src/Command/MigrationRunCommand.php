<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use LogicException;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function in_array;
use function is_string;
use function round;
use function sprintf;

#[AsCommand(self::NAME, description: 'Run all not executed migrations with specified phase')]
class MigrationRunCommand extends Command
{

    public const NAME = 'migration:run';

    public const ARGUMENT_PHASE = 'phase';
    public const PHASE_BOTH = 'both';

    private MigrationService $migrationService;

    public function __construct(
        MigrationService $migrationService,
    )
    {
        parent::__construct();

        $this->migrationService = $migrationService;
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

        $migratedSomething = $this->executeMigrations(
            $output,
            $this->getPhasesToRun($phaseArgument),
        );

        if (!$migratedSomething) {
            $output->writeln("<comment>No migration executed (phase {$phaseArgument}).</comment>");
        }

        return 0;
    }

    /**
     * @param list<MigrationPhase> $phases
     */
    private function executeMigrations(
        OutputInterface $output,
        array $phases,
    ): bool
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

        foreach ($preparedVersions as $version) {
            foreach ($phases as $phase) {
                if (isset($executed[$phase->value][$version])) {
                    continue;
                }

                $this->executeMigration($output, $version, $phase);
                $migratedSomething = true;
            }
        }

        return $migratedSomething;
    }

    private function executeMigration(
        OutputInterface $output,
        string $version,
        MigrationPhase $phase,
    ): void
    {
        $output->write("Executing migration {$version} phase {$phase->value}... ");

        $run = $this->migrationService->executeMigration($version, $phase);

        $elapsed = sprintf('%.3f', round($run->getDuration(), 3));
        $output->writeln("<info>done</info>, {$elapsed} s elapsed.");
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
