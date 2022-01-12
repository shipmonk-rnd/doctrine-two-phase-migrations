<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function in_array;
use function microtime;
use function round;
use function sprintf;

class MigrationRunCommand extends Command
{

    public const ARGUMENT_PHASE = 'phase';
    public const PHASE_BOTH = 'both';

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
            ->setName('migration:run')
            ->setDescription('Run all not executed migrations with specified phase')
            ->addArgument(self::ARGUMENT_PHASE, InputArgument::REQUIRED, MigrationPhase::BEFORE . '|' . MigrationPhase::AFTER . '|' . self::PHASE_BOTH);
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $phaseArgument = $input->getArgument(self::ARGUMENT_PHASE);

        $this->executeMigrations(
            $output,
            $this->getPhasesToRun($phaseArgument),
        );

        return 0;
    }

    /**
     * @param string[] $phases
     */
    private function executeMigrations(OutputInterface $output, array $phases): void
    {
        $executed = [];

        if (in_array(MigrationPhase::BEFORE, $phases, true)) {
            $executed[MigrationPhase::BEFORE] = $this->migrationService->getExecutedVersions(MigrationPhase::BEFORE);
        }

        if (in_array(MigrationPhase::AFTER, $phases, true)) {
            $executed[MigrationPhase::AFTER] = $this->migrationService->getExecutedVersions(MigrationPhase::AFTER);
        }

        $preparedVersions = $this->migrationService->getPreparedVersions();
        $migratedSomething = false;

        foreach ($preparedVersions as $version) {
            foreach ($phases as $phase) {
                if (isset($executed[$phase][$version])) {
                    continue;
                }

                $this->executeMigration($output, $version, $phase);
                $migratedSomething = true;
            }
        }

        if (!$migratedSomething) {
            $output->writeln("<comment>No migration executed in phase {$phase}.</comment>");
        }
    }

    private function executeMigration(OutputInterface $output, string $version, string $phase): void
    {
        $startTime = microtime(true);
        $output->write("Executing migration {$version} phase {$phase}... ");

        $this->migrationService->executeMigration($version, $phase);

        $elapsed = sprintf('%.2f', round(microtime(true) - $startTime, 2));
        $output->writeln("<info>done</info>, {$elapsed} s elapsed.");
    }

    /**
     * @param mixed $phaseArgument
     * @return string[]
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    private function getPhasesToRun($phaseArgument): array
    {
        if ($phaseArgument === MigrationPhase::BEFORE) {
            return [MigrationPhase::BEFORE];
        }

        if ($phaseArgument === MigrationPhase::AFTER) {
            return [MigrationPhase::AFTER];
        }

        if ($phaseArgument === self::PHASE_BOTH) {
            return [MigrationPhase::BEFORE, MigrationPhase::AFTER];
        }

        throw new LogicException('Unexpected phase argument');
    }

}
