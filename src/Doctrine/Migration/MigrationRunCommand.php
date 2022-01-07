<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\ORM\EntityManagerInterface;
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

    private const ARGUMENT_PHASE = 'phase';

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

        foreach (array_diff($prepared, $executed) as $version) {
            $this->executeMigration($output, $version, $phase);
        }

        return 0;
    }

    private function executeMigration(OutputInterface $output, string $version, string $phase): void
    {
        $startTime = microtime(true);
        $output->write("Executing migration {$version} phase {$phase}... ");

        $connection = $this->entityManager->getConnection();
        $migration = $this->migrationService->getMigration($version);

        if ($phase === MigrationPhase::BEFORE) {
            $migration->before($connection);
        }

        if ($phase === MigrationPhase::AFTER) {
            $migration->after($connection);
        }

        $this->migrationService->markMigrationExecuted($version, $phase);

        $elapsed = sprintf('%.2f', round(microtime(true) - $startTime, 2));
        $output->writeln("<info>done</info>, {$elapsed} s elapsed.");
    }

}
