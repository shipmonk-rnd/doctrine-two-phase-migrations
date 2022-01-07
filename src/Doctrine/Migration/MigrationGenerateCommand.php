<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Schema\Comparator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function count;

class MigrationGenerateCommand extends Command
{

    private EntityManagerInterface $entityManager;

    private MigrationService $migrationService;

    public function __construct(
        EntityManagerInterface $entityManager,
        MigrationService $migrationService
    )
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->migrationService = $migrationService;
    }

    protected function configure(): void
    {
        $this
            ->setName('migration:generate')
            ->setDescription('Generate migration class');
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaManager = $this->entityManager->getConnection()->getSchemaManager();
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        $classes = $this->entityManager->getMetadataFactory()->getAllMetadata();

        $fromSchema = $schemaManager->createSchema();
        $toSchema = $schemaTool->getSchemaFromMetadata($classes);

        $schemaComparator = new Comparator();
        $schemaDiff = $schemaComparator->compare($fromSchema, $toSchema);
        $sqls = $this->migrationService->shouldIncludeDropTableInDatabaseSync()
            ? $schemaDiff->toSql($platform)
            : $schemaDiff->toSaveSql($platform);

        if (count($sqls) === 0) {
            $output->writeln('<comment>No changes found, creating empty migration class...</comment>');
        }

        $file = $this->migrationService->generateMigrationFile($sqls);

        $output->writeln("<info>Migration version {$file->getVersion()} was generated</info>");
        return 0;
    }

}
