<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use DateTimeImmutable;
use DirectoryIterator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionFailedEvent;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionStartedEvent;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionSucceededEvent;
use Throwable;
use function file_put_contents;
use function is_string;
use function ksort;
use function str_replace;
use function strpos;

class MigrationService
{

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private MigrationConfig $config;

    private MigrationExecutor $executor;

    private MigrationVersionProvider $versionProvider;

    private MigrationAnalyzer $migrationAnalyzer;

    private ?EventDispatcherInterface $eventDispatcher;

    private MigrationGenerator $generator;

    public function __construct(
        EntityManagerInterface $entityManager,
        MigrationConfig $config,
        ?MigrationExecutor $executor = null,
        ?MigrationVersionProvider $versionProvider = null,
        ?MigrationAnalyzer $migrationAnalyzer = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?MigrationGenerator $generator = null,
    )
    {
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->config = $config;
        $this->executor = $executor ?? new MigrationDefaultExecutor($this->connection);
        $this->versionProvider = $versionProvider ?? new MigrationDefaultVersionProvider();
        $this->migrationAnalyzer = $migrationAnalyzer ?? new MigrationDefaultAnalyzer();
        $this->eventDispatcher = $eventDispatcher;
        $this->generator = $generator ?? new DefaultMigrationGenerator(
            $config->getTemplateFilePath(),
            $config->getTemplateIndent(),
        );
    }

    public function getConfig(): MigrationConfig
    {
        return $this->config;
    }

    private function getMigration(string $version): Migration
    {
        /** @var class-string<Migration> $fqn */
        $fqn = '\\' . $this->config->getMigrationClassNamespace() . '\\' . $this->config->getMigrationClassPrefix() . $version;
        return new $fqn();
    }

    public function executeMigration(
        string $version,
        MigrationPhase $phase,
    ): MigrationRun
    {
        $migration = $this->getMigration($version);

        $this->eventDispatcher?->dispatch(new MigrationExecutionStartedEvent($migration, $version, $phase));

        try {
            if ($migration instanceof TransactionalMigration) {
                $run = $this->connection->transactional(function () use ($migration, $version, $phase): MigrationRun {
                    return $this->doExecuteMigration($migration, $version, $phase);
                });
            } else {
                $run = $this->doExecuteMigration($migration, $version, $phase);
            }

            $this->eventDispatcher?->dispatch(new MigrationExecutionSucceededEvent($migration, $version, $phase));

        } catch (Throwable $e) {
            $this->eventDispatcher?->dispatch(new MigrationExecutionFailedEvent($migration, $version, $phase, $e));
            throw $e;
        }

        return $run;
    }

    private function doExecuteMigration(
        Migration $migration,
        string $version,
        MigrationPhase $phase,
    ): MigrationRun
    {
        $startTime = new DateTimeImmutable();

        match ($phase) {
            MigrationPhase::BEFORE => $migration->before($this->executor),
            MigrationPhase::AFTER => $migration->after($this->executor),
        };

        $endTime = new DateTimeImmutable();
        $run = new MigrationRun($version, $phase, $startTime, $endTime);

        $this->markMigrationExecuted($run);

        return $run;
    }

    /**
     * @return array<string, string>
     *
     * @phpstan-impure
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
    public function getExecutedVersions(MigrationPhase $phase): array
    {
        /** @var list<array{version: mixed}> $result */
        $result = $this->connection->executeQuery(
            'SELECT version FROM migration WHERE phase = :phase',
            [
                'phase' => $phase->value,
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
            'phase' => $run->getPhase()->value,
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

        $primaryKey = PrimaryKeyConstraint::editor()
            ->setUnquotedColumnNames('version', 'phase')
            ->create();

        $schema = new Schema();
        $table = $schema->createTable($migrationTableName);
        $table->addColumn('version', 'string', ['length' => 20]);
        $table->addColumn('phase', 'string', ['length' => 10]);
        $table->addColumn('started_at', 'string', ['length' => 30]); // string to support microseconds
        $table->addColumn('finished_at', 'string', ['length' => 30]);
        $table->addPrimaryKeyConstraint($primaryKey);

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

        $comparatorConfig = (new ComparatorConfig())->withReportModifiedIndexes(false);
        $schemaComparator = $schemaManager->createComparator($comparatorConfig);
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
     * @param list<string> $sqls
     */
    public function generateMigrationFile(array $sqls): MigrationFile
    {
        $statements = $this->migrationAnalyzer->analyze($sqls);
        $version = $this->versionProvider->getNextVersion();

        $className = $this->config->getMigrationClassPrefix() . $version;
        $namespace = $this->config->getMigrationClassNamespace();
        $content = $this->generator->generate($className, $namespace, $statements);

        $filePath = $this->config->getMigrationsDirectory() . '/' . $className . '.php';
        $migrationFile = new MigrationFile($filePath, $version, $content);

        $saved = file_put_contents($migrationFile->filePath, $migrationFile->content);

        if ($saved === false) {
            throw new LogicException("Unable to write new migration to {$migrationFile->filePath}");
        }

        return $migrationFile;
    }

}
