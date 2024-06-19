<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use DateTimeImmutable;
use DirectoryIterator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Throwable;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_string;
use function ksort;
use function sprintf;
use function str_replace;
use function strpos;
use const PHP_EOL;

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

    public function executeMigration(string $version, string $phase): MigrationRun
    {
        $migration = $this->getMigration($version);

        if ($migration instanceof TransactionalMigration) {
            try {
                $this->connection->beginTransaction();
                $run = $this->doExecuteMigration($migration, $version, $phase);
                $this->connection->commit();
            } catch (Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        } else {
            $run = $this->doExecuteMigration($migration, $version, $phase);
        }

        return $run;
    }

    private function doExecuteMigration(Migration $migration, string $version, string $phase): MigrationRun
    {
        $startTime = new DateTimeImmutable();

        if ($phase === MigrationPhase::BEFORE) {
            $migration->before($this->executor);
        } elseif ($phase === MigrationPhase::AFTER) {
            $migration->after($this->executor);
        } else {
            throw new LogicException("Invalid phase {$phase} given!");
        }

        $endTime = new DateTimeImmutable();
        $run = new MigrationRun($version, $phase, $startTime, $endTime);

        $this->markMigrationExecuted($run);

        return $run;
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

        /** @var DirectoryIterator $existingFile */
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
        $result = $this->connection->executeQuery(
            'SELECT version FROM migration WHERE phase = :phase',
            [
                'phase' => $phase,
            ],
        )->fetchAllAssociative();

        $versions = [];

        foreach ($result as $row) {
            $version = $row['version'];

            if (!is_string($version)) {
                throw new LogicException('Version should be a string.');
            }

            $versions[$version] = $version;
        }

        ksort($versions);

        return $versions;
    }

    public function markMigrationExecuted(MigrationRun $run): void
    {
        $microsecondsFormat = 'Y-m-d H:i:s.u';
        $this->connection->insert($this->config->getMigrationTableName(), [
            'version' => $run->getVersion(),
            'phase' => $run->getPhase(),
            'started_at' => $run->getStartedAt()->format($microsecondsFormat),
            'finished_at' => $run->getFinishedAt()->format($microsecondsFormat),
        ]);
    }

    public function initializeMigrationTable(): bool
    {
        $migrationTableName = $this->config->getMigrationTableName();

        if ($this->connection->createSchemaManager()->tablesExist([$migrationTableName])) {
            return false;
        }

        $schema = new Schema();
        $table = $schema->createTable($migrationTableName);
        $table->addColumn('version', 'string', ['length' => 20]);
        $table->addColumn('phase', 'string', ['length' => 10]);
        $table->addColumn('started_at', 'string', ['length' => 30]); // string to support microseconds
        $table->addColumn('finished_at', 'string', ['length' => 30]);
        $table->setPrimaryKey(['version', 'phase']);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeQuery($sql);
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function generateDiffSqls(): array
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        $classMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        $schemaManager = $this->entityManager->getConnection()->createSchemaManager();

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $schemaTool->getSchemaFromMetadata($classMetadata);

        $this->excludeTablesFromSchema($fromSchema);
        $this->excludeTablesFromSchema($toSchema);

        $schemaComparator = $schemaManager->createComparator();
        $schemaDiff = $schemaComparator->compareSchemas($fromSchema, $toSchema);

        return $platform->getAlterSchemaSQL($schemaDiff);
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
        $template = str_replace('%statements%', implode(PHP_EOL . $templateIndent, $statements), $template);

        $filePath = $migrationsDir . '/' . $migrationClassPrefix . $version . '.php';
        $saved = file_put_contents($filePath, $template);

        if ($saved === false) {
            throw new LogicException("Unable to write new migration to $filePath");
        }

        return new MigrationFile($filePath, $version);
    }

}
