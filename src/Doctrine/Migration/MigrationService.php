<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use DateTimeImmutable;
use DirectoryIterator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use SplFileInfo;
use Throwable;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function ksort;
use function method_exists;
use function sprintf;
use function str_replace;
use function strpos;

class MigrationService
{

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private MigrationConfig $config;

    private MigrationExecutor $executor;

    private MigrationVersionProvider $versionProvider;

    public function __construct(
        EntityManagerInterface $entityManager,
        MigrationConfig $config,
        ?MigrationExecutor $executor = null,
        ?MigrationVersionProvider $versionProvider = null
    )
    {
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->config = $config;
        $this->executor = $executor ?? new MigrationDefaultExecutor($this->connection);
        $this->versionProvider = $versionProvider ?? new MigrationDefaultVersionProvider();
    }

    public function getConfig(): MigrationConfig
    {
        return $this->config;
    }

    private function getMigration(string $version): Migration
    {
        /** @var class-string<Migration> $fqn allow-narrowing */
        $fqn = '\\' . $this->config->getMigrationClassNamespace() . '\\' . $this->config->getMigrationClassPrefix() . $version;
        return new $fqn();
    }

    public function executeMigration(string $version, string $phase): void
    {
        $migration = $this->getMigration($version);

        if ($migration instanceof TransactionalMigration) {
            try {
                $this->connection->beginTransaction();
                $this->doExecuteMigration($migration, $version, $phase);
                $this->connection->commit();
            } catch (Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        } else {
            $this->doExecuteMigration($migration, $version, $phase);
        }
    }

    private function doExecuteMigration(Migration $migration, string $version, string $phase): void
    {
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
        $classPrefix = $this->config->getMigrationClassPrefix();

        $migrationDirIterator = new DirectoryIterator($this->config->getMigrationsDirectory());

        /** @var SplFileInfo $existingFile */
        foreach ($migrationDirIterator as $existingFile) {
            if (
                !$existingFile->isFile()
                || $existingFile->getExtension() !== 'php'
                || strpos($existingFile->getFilename(), $classPrefix) === false
            ) {
                continue;
            }

            $version = str_replace($classPrefix, '', $existingFile->getBasename('.php'));
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
        $this->connection->insert($this->config->getMigrationTableName(), [
            'version' => $version,
            'phase' => $phase,
            'executed' => $executedAt,
        ], [
            'executed' => 'datetimetz_immutable',
        ]);
    }

    public function initializeMigrationTable(): bool
    {
        $migrationTableName = $this->config->getMigrationTableName();

        if ($this->connection->getSchemaManager()->tablesExist([$migrationTableName])) {
            return false;
        }

        $schema = new Schema();
        $table = $schema->createTable($migrationTableName);
        $table->addColumn('version', 'string');
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
        $toSchema = $schemaTool->getSchemaFromMetadata(array_values($classMetadata));

        $this->excludeTablesFromSchema($fromSchema);
        $this->excludeTablesFromSchema($toSchema);

        if (method_exists($schemaManager, 'createComparator')) {
            $schemaComparator = $schemaManager->createComparator();
        } else {
            $schemaComparator = new Comparator();
        }

        $schemaDiff = $schemaComparator->compareSchemas($fromSchema, $toSchema);
        return $schemaDiff->toSql($platform);
    }

    private function excludeTablesFromSchema(Schema $schema): void
    {
        foreach ($this->config->getExcludedTables() as $table) {
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

        $templateFilePath = $this->config->getTemplateFilePath();
        $templateIndent = $this->config->getTemplateIndent();
        $migrationsDir = $this->config->getMigrationsDirectory();
        $migrationClassPrefix = $this->config->getMigrationClassPrefix();
        $migrationClassNamespace = $this->config->getMigrationClassNamespace();

        $version = $this->versionProvider->getNextVersion();
        $template = file_get_contents($templateFilePath);

        if ($template === false) {
            throw new LogicException("Unable to read $templateFilePath");
        }

        $template = str_replace('%namespace%', $migrationClassNamespace, $template);
        $template = str_replace('%version%', $version, $template);
        $template = str_replace('%statements%', implode("\n" . $templateIndent, $statements), $template);

        $filePath = $migrationsDir . '/' . $migrationClassPrefix . $version . '.php';
        $saved = file_put_contents($filePath, $template);

        if ($saved === false) {
            throw new LogicException("Unable to write new migration to $filePath");
        }

        return new MigrationFile($filePath, $version);
    }

}
