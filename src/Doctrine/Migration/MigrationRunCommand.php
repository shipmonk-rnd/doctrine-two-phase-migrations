<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function microtime;
use function round;
use function sprintf;

class MigrationRunCommand extends Command
{

    public const ARGUMENT_PHASE = 'phase';

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
            ->addArgument(self::ARGUMENT_PHASE, InputArgument::REQUIRED, MigrationPhase::BEFORE . '|' . MigrationPhase::AFTER);
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $phase = $input->getArgument(self::ARGUMENT_PHASE);

        if ($phase !== MigrationPhase::BEFORE && $phase !== MigrationPhase::AFTER) {
            $output->writeln('<error>Unexpected phase given, use before|after</error>');
            return 1;
        }

        $executed = $this->migrationService->getExecutedVersions($phase);
        $prepared = $this->migrationService->getPreparedVersions();
        $toBeExecuted = array_diff($prepared, $executed);

        foreach ($toBeExecuted as $version) {
            $this->executeMigration($output, $version, $phase);
        }

        if ($toBeExecuted === []) {
            $output->writeln('<comment>No migration executed.</comment>');
        }

        return 0;
    }

    private function executeMigration(OutputInterface $output, string $version, string $phase): void
    {
        $startTime = microtime(true);
        $output->write("Executing migration {$version} phase {$phase}... ");

        $this->migrationService->executeMigration($version, $phase);

        $elapsed = sprintf('%.2f', round(microtime(true) - $startTime, 2));
        $output->writeln("<info>done</info>, {$elapsed} s elapsed.");
    }

}
