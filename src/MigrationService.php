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
use function array_map;
use function count;
use function file_put_contents;
use function implode;
use function is_string;
use function ksort;
use function sprintf;
use function str_replace;
use function strpos;

class MigrationService
{

    private const DATETIME_FORMAT = 'Y-m-d H:i:s.u'; // string with microseconds, see initializeMigrationTable()

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private MigrationConfig $config;

    private MigrationExecutor $executor;

    private MigrationVersionProvider $versionProvider;

    private MigrationAnalyzer $migrationAnalyzer;

    private ?EventDispatcherInterface $eventDispatcher;

    private MigrationGenerator $generator;

    private ?MigrationLock $lock = null;

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

    private function getQuotedMigrationTableName(): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->config->getMigrationTableName());
    }

    public function executeMigration(
        string $version,
        MigrationPhase $phase,
    ): MigrationRun
    {
        $migration = $this->getMigration($version);

        $this->eventDispatcher?->dispatch(new MigrationExecutionStartedEvent($migration, $version, $phase));

        try {
            // mark the start outside the (optional) body transaction, so an interrupted migration stays detectable
            $startedAt = new DateTimeImmutable();
            $this->markMigrationStarted($version, $phase, $startedAt);

            $runBody = function () use ($migration, $phase): void {
                match ($phase) {
                    MigrationPhase::BEFORE => $migration->before($this->executor),
                    MigrationPhase::AFTER => $migration->after($this->executor),
                };
            };

            if ($migration instanceof TransactionalMigration) {
                $this->connection->transactional($runBody);
            } else {
                $runBody();
            }

            $finishedAt = new DateTimeImmutable();
            $this->markMigrationFinished($version, $phase, $finishedAt);

            $run = new MigrationRun($version, $phase, $startedAt, $finishedAt);

            $this->eventDispatcher?->dispatch(new MigrationExecutionSucceededEvent($migration, $version, $phase));

        } catch (Throwable $e) {
            $this->eventDispatcher?->dispatch(new MigrationExecutionFailedEvent($migration, $version, $phase, $e));
            throw $e;
        }

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
            'SELECT version FROM ' . $this->getQuotedMigrationTableName() . ' WHERE phase = :phase',
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

    /**
     * Returns migrations that were started but never finished (finished_at IS NULL), i.e. interrupted runs.
     * Such migrations may be partially applied, so the system must not silently continue.
     *
     * @return list<array{version: string, phase: string, startedAt: string}>
     */
    public function getIncompleteMigrations(): array
    {
        /** @var list<array{version: mixed, phase: mixed, started_at: mixed}> $result */
        $result = $this->connection->executeQuery(
            'SELECT version, phase, started_at FROM ' . $this->getQuotedMigrationTableName() . ' WHERE finished_at IS NULL ORDER BY started_at',
        )->fetchAllAssociative();

        $incomplete = [];

        foreach ($result as $row) {
            $version = $row['version'];
            $phase = $row['phase'];
            $startedAt = $row['started_at'];

            if (!is_string($version) || !is_string($phase) || !is_string($startedAt)) {
                throw new LogicException('Version, phase and started_at should be strings.');
            }

            $incomplete[] = ['version' => $version, 'phase' => $phase, 'startedAt' => $startedAt];
        }

        return $incomplete;
    }

    /**
     * @throws IncompleteMigrationException when there are migrations that were started but never finished
     */
    public function assertNoIncompleteMigrations(): void
    {
        $incomplete = $this->getIncompleteMigrations();

        if ($incomplete === []) {
            return;
        }

        $list = implode(', ', array_map(
            static fn (array $migration): string => sprintf(
                '%s (phase %s, started at %s)',
                $migration['version'],
                $migration['phase'],
                $migration['startedAt'],
            ),
            $incomplete,
        ));

        throw new IncompleteMigrationException($incomplete, sprintf(
            'Cannot run migrations, found %d unfinished migration(s) from a previously interrupted run: %s. '
                . 'Such migrations may be partially applied. Verify the database state manually and then either delete the '
                . 'row from the %s table to re-run the migration, or set its finished_at to mark it as completed.',
            count($incomplete),
            $list,
            $this->config->getMigrationTableName(),
        ));
    }

    /**
     * Ensures the migration table exists and uses the current schema (finished_at nullable).
     * Guards against running migrations after a library upgrade where migration:init was not re-run,
     * which would otherwise fail with a cryptic NOT NULL constraint violation.
     *
     * @throws MigrationTableNotInitializedException
     */
    public function assertMigrationTableUpToDate(): void
    {
        $migrationTableName = $this->config->getMigrationTableName();
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist([$migrationTableName])) {
            throw new MigrationTableNotInitializedException($migrationTableName, sprintf(
                'Migration table "%s" does not exist. Run the migration:init command before running migrations.',
                $migrationTableName,
            ));
        }

        $table = $schemaManager->introspectTable($migrationTableName);

        if (!$table->hasColumn('finished_at') || $table->getColumn('finished_at')->getNotnull()) {
            throw new MigrationTableNotInitializedException($migrationTableName, sprintf(
                'Migration table "%s" schema is out of date (the finished_at column must exist and be nullable). '
                    . 'Run the migration:init command to upgrade it before running migrations.',
                $migrationTableName,
            ));
        }
    }

    public function markMigrationExecuted(MigrationRun $run): void
    {
        $this->connection->insert($this->config->getMigrationTableName(), [
            'version' => $run->getVersion(),
            'phase' => $run->getPhase()->value,
            'started_at' => $run->getStartedAt()->format(self::DATETIME_FORMAT),
            'finished_at' => $run->getFinishedAt()->format(self::DATETIME_FORMAT),
        ]);
    }

    private function markMigrationStarted(
        string $version,
        MigrationPhase $phase,
        DateTimeImmutable $startedAt,
    ): void
    {
        $this->connection->insert($this->config->getMigrationTableName(), [
            'version' => $version,
            'phase' => $phase->value,
            'started_at' => $startedAt->format(self::DATETIME_FORMAT),
            'finished_at' => null,
        ]);
    }

    private function markMigrationFinished(
        string $version,
        MigrationPhase $phase,
        DateTimeImmutable $finishedAt,
    ): void
    {
        $this->connection->update(
            $this->config->getMigrationTableName(),
            ['finished_at' => $finishedAt->format(self::DATETIME_FORMAT)],
            ['version' => $version, 'phase' => $phase->value],
        );
    }

    public function acquireLock(): void
    {
        $this->getLock()->acquire();
    }

    public function releaseLock(): void
    {
        $this->getLock()->release();
    }

    private function getLock(): MigrationLock
    {
        return $this->lock ??= new MigrationLock(
            $this->connection,
            $this->config->getMigrationTableName(),
            $this->config->getLockTimeoutSeconds(),
        );
    }

    /**
     * Creates the migration table when missing, or idempotently upgrades it when present. Safe to run repeatedly.
     */
    public function initializeMigrationTable(): MigrationTableState
    {
        $migrationTableName = $this->config->getMigrationTableName();

        if ($this->connection->createSchemaManager()->tablesExist([$migrationTableName])) {
            return $this->upgradeMigrationTable($migrationTableName);
        }

        $primaryKey = PrimaryKeyConstraint::editor()
            ->setUnquotedColumnNames('version', 'phase')
            ->create();

        $schema = new Schema();
        $table = $schema->createTable($migrationTableName);
        $table->addColumn('version', 'string', ['length' => 20]);
        $table->addColumn('phase', 'string', ['length' => 10]);
        $table->addColumn('started_at', 'string', ['length' => 30]); // string to support microseconds
        $table->addColumn('finished_at', 'string', ['length' => 30, 'notnull' => false]); // nullable: NULL marks an unfinished (interrupted) migration
        $table->addPrimaryKeyConstraint($primaryKey);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        return MigrationTableState::Created;
    }

    /**
     * Idempotently makes finished_at nullable on an already existing migration table (upgrade from < 2.0).
     * Only this single column is touched, so no unrelated schema differences can be applied.
     */
    private function upgradeMigrationTable(
        string $migrationTableName,
    ): MigrationTableState
    {
        $schemaManager = $this->connection->createSchemaManager();
        $currentTable = $schemaManager->introspectTable($migrationTableName);

        if (!$currentTable->hasColumn('finished_at') || !$currentTable->getColumn('finished_at')->getNotnull()) {
            return MigrationTableState::AlreadyUpToDate;
        }

        $desiredTable = $schemaManager->introspectTable($migrationTableName);
        $desiredTable->modifyColumn('finished_at', ['notnull' => false]);

        $comparatorConfig = (new ComparatorConfig())->withReportModifiedIndexes(false);
        $tableDiff = $schemaManager->createComparator($comparatorConfig)->compareTables($currentTable, $desiredTable);

        if ($tableDiff->isEmpty()) {
            return MigrationTableState::AlreadyUpToDate;
        }

        $schemaManager->alterTable($tableDiff);

        return MigrationTableState::Upgraded;
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
