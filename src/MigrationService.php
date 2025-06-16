<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use DateTimeImmutable;
use DirectoryIterator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionFailedEvent;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionStartedEvent;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionSucceededEvent;
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

    private MigrationAnalyzer $migrationAnalyzer;

    private ?EventDispatcherInterface $eventDispatcher;

    public function __construct(
        EntityManagerInterface $entityManager,
        MigrationConfig $config,
        ?MigrationExecutor $executor = null,
        ?MigrationVersionProvider $versionProvider = null,
        ?MigrationAnalyzer $migrationAnalyzer = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    )
    {
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->config = $config;
        $this->executor = $executor ?? new MigrationDefaultExecutor($this->connection);
        $this->versionProvider = $versionProvider ?? new MigrationDefaultVersionProvider();
        $this->migrationAnalyzer = $migrationAnalyzer ?? new MigrationDefaultAnalyzer();
        $this->eventDispatcher = $eventDispatcher;
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
     * @param list<string|Statement> $sqls
     */
    public function generateMigrationFile(array $sqls): MigrationFile
    {
        $statements = $this->migrationAnalyzer->analyze($sqls);
        $statementsBefore = $statementsAfter = [];

        foreach ($statements as $statement) {
            if ($statement->phase === MigrationPhase::AFTER) {
                $statementsAfter[] = sprintf("\$executor->executeQuery('%s');", str_replace("'", "\'", $statement->sql));
            } else {
                $statementsBefore[] = sprintf("\$executor->executeQuery('%s');", str_replace("'", "\'", $statement->sql));
            }
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
        $template = str_replace('%statements%', implode(PHP_EOL . $templateIndent, $statementsBefore), $template);
        $template = str_replace('%statementsAfter%', implode(PHP_EOL . $templateIndent, $statementsAfter), $template);

        $filePath = $migrationsDir . '/' . $migrationClassPrefix . $version . '.php';
        $saved = file_put_contents($filePath, $template);

        if ($saved === false) {
            throw new LogicException("Unable to write new migration to $filePath");
        }

        return new MigrationFile($filePath, $version);
    }

}
