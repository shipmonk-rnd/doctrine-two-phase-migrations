<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use SplFileInfo;
use function date;
use function implode;
use function ksort;
use function sprintf;
use function str_replace;
use function strlen;
use function trim;

class MigrationService
{

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private MigrationExecutor $executor;

    private string $migrationsDir;

    private string $migrationClassNamespace;

    private string $migrationClassPrefix;

    /**
     * @var string[]
     */
    private array $excludedTables;

    private string $templateFilePath;

    private string $templateIndent;

    /**
     * @param string[] $excludedTables
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ?MigrationExecutor $migrationExecutor,
        string $migrationsDir,
        string $migrationClassNamespace = 'Migrations',
        string $migrationClassPrefix = 'Migration',
        array $excludedTables = [],
        string $templateFilePath = __DIR__ . '/template/migration.txt',
        string $templateIndent = '        '
    )
    {
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->executor = $migrationExecutor ?? new MigrationDefaultExecutor($this->connection);
        $this->migrationsDir = $migrationsDir;
        $this->migrationClassNamespace = trim($migrationClassNamespace, '\\');
        $this->migrationClassPrefix = $migrationClassPrefix;
        $this->excludedTables = $excludedTables;
        $this->excludedTables[] = $this->getMigrationTableName();
        $this->templateFilePath = $templateFilePath;
        $this->templateIndent = $templateIndent;
    }

    public function getMigrationsDir(): string
    {
        return $this->migrationsDir;
    }

    private function getMigrationClassNamespace(): string
    {
        return $this->migrationClassNamespace;
    }

    /**
     * @return string[]
     */
    public function getExcludedTables(): array
    {
        return $this->excludedTables;
    }

    private function getMigrationClassPrefix(): string
    {
        return $this->migrationClassPrefix;
    }

    public function getMigrationTableName(): string
    {
        return 'migration';
    }

    private function getMigration(string $version): Migration
    {
        /** @var class-string<Migration> $fqn */
        $fqn = '\\' . $this->getMigrationClassNamespace() . '\\' . $this->getMigrationClassPrefix() . $version;
        return new $fqn();
    }

    public function executeMigration(string $version, string $phase): void
    {
        $migration = $this->getMigration($version);

        if ($phase === MigrationPhase::BEFORE) {
            $migration->before($this->executor);

        } elseif ($phase === MigrationPhase::AFTER) {
            $migration->after($this->executor);

        } else {
            throw new LogicException("Invalid phase {$phase} given!");
        }

        $this->markMigrationExecuted($version, $phase, new DateTimeImmutable());
    }

    /**
     * @phpstan-impure
     * @return array<string, string>
     */
    public function getPreparedVersions(): array
    {
        $migrations = [];

        /** @var SplFileInfo $fileinfo */
        foreach (Finder::findFiles($this->getMigrationClassPrefix() . '*.php')->in($this->migrationsDir) as $fileinfo) {
            $version = str_replace($this->getMigrationClassPrefix(), '', $fileinfo->getBasename('.php'));
            $migrations[$version] = $version;
        }

        ksort($migrations);

        return $migrations;
    }

    /**
     * @return array<string, string>
     */
    public function getExecutedVersions(string $phase): array
    {
        /** @var array<array{ version: string }> $result */
        $result = $this->connection->executeQuery(
            'SELECT version FROM migration WHERE phase = :phase',
            [
                'phase' => $phase,
            ],
        )->fetchAll();

        $versions = [];

        foreach ($result as $row) {
            $version = $row['version'];
            $versions[$version] = $version;
        }

        ksort($versions);

        return $versions;
    }

    public function markMigrationExecuted(string $version, string $phase, DateTimeImmutable $executedAt): void
    {
        $this->connection->insert($this->getMigrationTableName(), [
            'version' => $version,
            'phase' => $phase,
            'executed' => $executedAt,
        ], [
            'executed' => 'datetimetz_immutable',
        ]);
    }

    public function initializeMigrationTable(): bool
    {
        $migrationTableName = $this->getMigrationTableName();

        if ($this->connection->getSchemaManager()->tablesExist([$migrationTableName])) {
            return false;
        }

        $schema = new Schema();
        $table = $schema->createTable($migrationTableName);
        $table->addColumn('version', 'string', ['length' => strlen($this->getNextVersion())]);
        $table->addColumn('phase', 'string', ['length' => 6]);
        $table->addColumn('executed', 'datetimetz_immutable');
        $table->setPrimaryKey(['version', 'phase']);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeQuery($sql);
        }

        return true;
    }

    /**
     * @return string[]
     */
    public function generateDiffSqls(): array
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        $classMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        $schemaManager = $this->entityManager->getConnection()->getSchemaManager();
        $fromSchema = $schemaManager->createSchema();
        $toSchema = $schemaTool->getSchemaFromMetadata($classMetadata);

        $this->excludeTablesFromSchema($fromSchema);
        $this->excludeTablesFromSchema($toSchema);

        $schemaComparator = $schemaManager->createComparator();
        $schemaDiff = $schemaComparator->compareSchemas($fromSchema, $toSchema);
        return $schemaDiff->toSql($platform);
    }

    private function excludeTablesFromSchema(Schema $schema): void
    {
        foreach ($this->getExcludedTables() as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }

    /**
     * @param string[] $sqls
     */
    public function generateMigrationFile(array $sqls): MigrationFile
    {
        $statements = [];

        foreach ($sqls as $sql) {
            $statements[] = sprintf("\$executor->executeQuery('%s');", str_replace("'", "\'", $sql));
        }

        $migrationsDir = $this->getMigrationsDir();
        $migrationClassPrefix = $this->getMigrationClassPrefix();
        $migrationClassNamespace = $this->getMigrationClassNamespace();

        $version = $this->getNextVersion();
        $template = FileSystem::read($this->templateFilePath);
        $template = str_replace('%namespace%', $migrationClassNamespace, $template);
        $template = str_replace('%version%', $version, $template);
        $template = str_replace('%statements%', implode("\n" . $this->templateIndent, $statements), $template);

        $filePath = $migrationsDir . '/' . $migrationClassPrefix . $version . '.php';
        FileSystem::createDir($migrationsDir, 655);
        FileSystem::write($filePath, $template);

        return new MigrationFile($filePath, $version);
    }

    private function getNextVersion(): string
    {
        return date('YmdHis');
    }

}
