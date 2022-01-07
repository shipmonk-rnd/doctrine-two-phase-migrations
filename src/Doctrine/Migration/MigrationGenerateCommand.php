<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Schema\Comparator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nette\Utils\FileSystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function count;
use function date;
use function implode;
use function sprintf;
use function str_replace;

class MigrationGenerateCommand extends Command
{

    private EntityManagerInterface $entityManager;

    private MigrationService $migrationService;

    private string $templateFilePath;

    private string $templateIndent;

    public function __construct(
        EntityManagerInterface $entityManager,
        MigrationService $migrationService,
        string $templateFilePath = __DIR__ . '/template/migration.txt',
        string $templateIndent = '        ',
    )
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->migrationService = $migrationService;
        $this->templateFilePath = $templateFilePath;
        $this->templateIndent = $templateIndent;
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

        $version = $this->saveMigration($sqls);

        $output->writeln("<info>Migration version $version was generated</info>");
        return 0;
    }

    /**
     * @param string[] $sqls
     */
    private function saveMigration(array $sqls): string
    {
        $statements = [];

        foreach ($sqls as $sql) {
            $statements[] = sprintf("\$connection->executeQuery('%s');", str_replace("'", "\'", $sql));
        }

        $migrationsDir = $this->migrationService->getMigrationsDir();
        $migrationClassPrefix = $this->migrationService->getMigrationClassPrefix();
        $migrationClassNamespace = $this->migrationService->getMigrationClassNamespace();

        $version = date('YmdHis');
        $template = FileSystem::read($this->templateFilePath);
        $template = str_replace('%namespace%', $migrationClassNamespace, $template);
        $template = str_replace('%version%', $version, $template);
        $template = str_replace('%statements%', implode("\n" . $this->templateIndent, $statements), $template);

        FileSystem::createDir($migrationsDir);
        FileSystem::write($migrationsDir . '/' . $migrationClassPrefix . $version . '.php', $template);

        return $version;
    }

}
