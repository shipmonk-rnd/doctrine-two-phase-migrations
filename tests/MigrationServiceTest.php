<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionStartedEvent;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionSucceededEvent;
use Throwable;
use function array_map;
use function file_get_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function str_contains;
use function touch;

class MigrationServiceTest extends TestCase
{

    use WithEntityManagerTestCase;

    public function testInitGenerationExecution(): void
    {
        $invokedCount = self::exactly(4);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($invokedCount)
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use ($invokedCount): object {
                match ($invokedCount->numberOfInvocations()) {
                    1 => self::assertInstanceOf(MigrationExecutionStartedEvent::class, $event, $event::class),
                    2 => self::assertInstanceOf(MigrationExecutionSucceededEvent::class, $event, $event::class),
                    3 => self::assertInstanceOf(MigrationExecutionStartedEvent::class, $event, $event::class),
                    4 => self::assertInstanceOf(MigrationExecutionSucceededEvent::class, $event, $event::class),
                    default => self::fail('Unexpected event'),
                };
                return $event;
            });

        [$entityManager] = $this->createEntityManagerAndLogger();
        $connection = $entityManager->getConnection();
        $service = $this->createMigrationService($entityManager, eventDispatcher: $eventDispatcher);

        $migrationTableName = $service->getConfig()->getMigrationTableName();

        $initialized1 = $service->initializeMigrationTable();
        $initialized2 = $service->initializeMigrationTable(); // double init should not fail

        $sqls = $service->generateDiffSqls();

        self::assertSame(MigrationTableState::Created, $initialized1);
        self::assertSame(MigrationTableState::AlreadyUpToDate, $initialized2);
        self::assertSame(['CREATE TABLE entity (id VARCHAR(255) NOT NULL, PRIMARY KEY (id))'], $sqls);

        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertEquals([], $service->getPreparedVersions());

        $generatedFile = $service->generateMigrationFile($sqls);
        $generatedVersion = $generatedFile->version;
        $generatedContents = file_get_contents($generatedFile->filePath);

        self::assertNotFalse($generatedContents);

        require $generatedFile->filePath;

        foreach ($sqls as $sql) {
            self::assertStringContainsString($sql, $generatedContents);
        }

        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertEquals([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertCount(0, $connection->executeQuery("SELECT * FROM {$migrationTableName}")->fetchAllAssociative());

        $service->executeMigration($generatedVersion, MigrationPhase::BEFORE);

        self::assertEquals([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertEquals([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertCount(1, $connection->executeQuery("SELECT * FROM {$migrationTableName}")->fetchAllAssociative());

        $service->executeMigration($generatedVersion, MigrationPhase::AFTER);

        self::assertEquals([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertEquals([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertEquals([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertCount(2, $connection->executeQuery("SELECT * FROM {$migrationTableName}")->fetchAllAssociative());

        $sqls2 = $service->generateDiffSqls();

        self::assertEquals([], $sqls2); // no diff after migration
    }

    public function testTransactionalExecution(): void
    {
        $versionProvider = new class implements MigrationVersionProvider {

            private int $lastVersion = 0;

            public function getNextVersion(): string
            {
                return 'tx' . ++$this->lastVersion;
            }

        };
        [$entityManager, $logger] = $this->createEntityManagerAndLogger();

        $nonTransactionalService = $this->createMigrationService($entityManager, [], false, $versionProvider);
        $transactionalService = $this->createMigrationService($entityManager, [], true, $versionProvider);

        $transactionalService->initializeMigrationTable();
        $logger->clean();

        $transactionalMigrationFile = $transactionalService->generateMigrationFile([]);
        $nonTransactionalMigrationFile = $nonTransactionalService->generateMigrationFile([]);

        require $transactionalMigrationFile->filePath;
        require $nonTransactionalMigrationFile->filePath;

        $transactionalService->executeMigration($transactionalMigrationFile->version, MigrationPhase::BEFORE);

        self::assertSame([
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)', // start marker, committed before the body
            'Beginning transaction',
            'Committing transaction',
            'UPDATE migration SET finished_at = ? WHERE version = ? AND phase = ?', // finish marker, after the body
        ], $logger->getQueriesPerformed());

        $logger->clean();

        $nonTransactionalService->executeMigration($nonTransactionalMigrationFile->version, MigrationPhase::BEFORE);

        self::assertSame([
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)', // start marker
            'UPDATE migration SET finished_at = ? WHERE version = ? AND phase = ?', // finish marker
        ], $logger->getQueriesPerformed());
    }

    public function testBothPhasesGenerated(): void
    {
        $versionProvider = new class implements MigrationVersionProvider {

            private int $lastVersion = 0;

            public function getNextVersion(): string
            {
                return 'beforeAfter' . ++$this->lastVersion;
            }

        };
        [$entityManager, $logger] = $this->createEntityManagerAndLogger();

        $migrationsService = $this->createMigrationService($entityManager, versionProvider: $versionProvider);

        $migrationsService->initializeMigrationTable();
        $logger->clean();

        $migrationsService = $this->createMigrationService($entityManager, versionProvider: $versionProvider, statementAnalyzer: $this->createPhaseRoutingAnalyzer());

        $migrationFile = $migrationsService->generateMigrationFile([
            'SELECT 1',
            'SELECT 2',
            'SELECT 3',
        ]);

        require $migrationFile->filePath;

        $migrationsService->executeMigration($migrationFile->version, MigrationPhase::BEFORE);

        self::assertSame([
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
            'SELECT 1',
            'SELECT 3',
            'UPDATE migration SET finished_at = ? WHERE version = ? AND phase = ?',
        ], $logger->getQueriesPerformed());

        $logger->clean();

        $migrationsService->executeMigration($migrationFile->version, MigrationPhase::AFTER);

        self::assertSame([
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
            'SELECT 2',
            'UPDATE migration SET finished_at = ? WHERE version = ? AND phase = ?',
        ], $logger->getQueriesPerformed());
    }

    public function testPhasesSorting(): void
    {
        $versionProvider = new class implements MigrationVersionProvider {

            private int $lastVersion = 0;

            public function getNextVersion(): string
            {
                return 'sort' . ++$this->lastVersion;
            }

        };
        [$entityManager, $logger] = $this->createEntityManagerAndLogger();

        $migrationsService = $this->createMigrationService($entityManager, [], false, $versionProvider, $this->createPhaseRoutingAnalyzer());

        $migrationsService->initializeMigrationTable();
        $logger->clean();

        $migrationFile = $migrationsService->generateMigrationFile(['SELECT 1', 'SELECT 2']);

        require $migrationFile->filePath;

        $migrationsService->executeMigration($migrationFile->version, MigrationPhase::BEFORE);

        self::assertSame([
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
            'SELECT 1',
            'UPDATE migration SET finished_at = ? WHERE version = ? AND phase = ?',
        ], $logger->getQueriesPerformed());

        $logger->clean();

        $migrationsService->executeMigration($migrationFile->version, MigrationPhase::AFTER);

        self::assertSame([
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
            'SELECT 2',
            'UPDATE migration SET finished_at = ? WHERE version = ? AND phase = ?',
        ], $logger->getQueriesPerformed());
    }

    public function testInitialization(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);

        self::assertSame(MigrationTableState::Created, $service->initializeMigrationTable());

        $migrationTableName = $service->getConfig()->getMigrationTableName();
        $schemaManager = $entityManager->getConnection()->createSchemaManager();

        $table = $schemaManager->introspectTable($migrationTableName);

        self::assertTrue($table->hasColumn('version'));
        self::assertTrue($table->hasColumn('phase'));
        self::assertTrue($table->hasColumn('started_at'));
        self::assertTrue($table->hasColumn('finished_at'));
        self::assertTrue($table->getColumn('started_at')->getNotnull());
        self::assertFalse($table->getColumn('finished_at')->getNotnull()); // nullable: NULL marks an unfinished migration
        self::assertNotNull($table->getPrimaryKeyConstraint());

        self::assertSame(MigrationTableState::AlreadyUpToDate, $service->initializeMigrationTable());
    }

    public function testInitializeUpgradesFinishedAtToNullable(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);
        $connection = $entityManager->getConnection();
        $migrationTableName = $service->getConfig()->getMigrationTableName();

        // simulate the pre-2.0 schema where finished_at was NOT NULL
        $connection->executeStatement(
            "CREATE TABLE {$migrationTableName} ("
                . 'version VARCHAR(20) NOT NULL, '
                . 'phase VARCHAR(10) NOT NULL, '
                . 'started_at VARCHAR(30) NOT NULL, '
                . 'finished_at VARCHAR(30) NOT NULL, '
                . 'PRIMARY KEY (version, phase))',
        );
        $connection->insert($migrationTableName, [
            'version' => 'v1',
            'phase' => 'before',
            'started_at' => '2023-01-01 00:00:00.000000',
            'finished_at' => '2023-01-01 00:00:01.000000',
        ]);

        self::assertTrue($entityManager->getConnection()->createSchemaManager()->introspectTable($migrationTableName)->getColumn('finished_at')->getNotnull());

        $upgraded = $service->initializeMigrationTable();

        self::assertSame(MigrationTableState::Upgraded, $upgraded);

        $table = $entityManager->getConnection()->createSchemaManager()->introspectTable($migrationTableName);
        self::assertFalse($table->getColumn('finished_at')->getNotnull());
        self::assertNotNull($table->getPrimaryKeyConstraint());

        // data preserved and a NULL finished_at can now be stored
        self::assertSame(['v1' => 'v1'], $service->getExecutedVersions(MigrationPhase::BEFORE));
        $connection->insert($migrationTableName, ['version' => 'v2', 'phase' => 'before', 'started_at' => '2023-01-02 00:00:00.000000', 'finished_at' => null]);
        self::assertSame([['version' => 'v2', 'phase' => 'before', 'startedAt' => '2023-01-02 00:00:00.000000']], $service->getIncompleteMigrations());

        self::assertSame(MigrationTableState::AlreadyUpToDate, $service->initializeMigrationTable()); // idempotent, no further upgrade
    }

    public function testGetIncompleteMigrations(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);
        $connection = $entityManager->getConnection();
        $migrationTableName = $service->getConfig()->getMigrationTableName();

        $service->initializeMigrationTable();

        self::assertSame([], $service->getIncompleteMigrations());
        $service->assertNoIncompleteMigrations(); // does not throw

        // a finished migration is not reported as incomplete
        $connection->insert($migrationTableName, ['version' => 'v1', 'phase' => 'before', 'started_at' => '2023-01-01 00:00:00.000000', 'finished_at' => '2023-01-01 00:00:01.000000']);
        // an interrupted migration leaves finished_at = NULL
        $connection->insert($migrationTableName, ['version' => 'v2', 'phase' => 'after', 'started_at' => '2023-01-02 00:00:00.000000', 'finished_at' => null]);

        self::assertSame([
            ['version' => 'v2', 'phase' => 'after', 'startedAt' => '2023-01-02 00:00:00.000000'],
        ], $service->getIncompleteMigrations());
    }

    public function testAssertNoIncompleteMigrationsThrows(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);
        $connection = $entityManager->getConnection();
        $migrationTableName = $service->getConfig()->getMigrationTableName();

        $service->initializeMigrationTable();
        $connection->insert($migrationTableName, ['version' => 'v1', 'phase' => 'before', 'started_at' => '2023-01-01 00:00:00.000000', 'finished_at' => null]);

        try {
            $service->assertNoIncompleteMigrations();
            self::fail('Expected IncompleteMigrationException');
        } catch (IncompleteMigrationException $e) {
            self::assertSame([['version' => 'v1', 'phase' => 'before', 'startedAt' => '2023-01-01 00:00:00.000000']], $e->incompleteMigrations);
            self::assertStringContainsString('v1', $e->getMessage());
        }
    }

    public function testInterruptedMigrationLeavesIncompleteMarker(): void
    {
        $versionProvider = new class implements MigrationVersionProvider {

            public function getNextVersion(): string
            {
                return 'failing1';
            }

        };
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager, versionProvider: $versionProvider);

        $service->initializeMigrationTable();

        $migrationFile = $service->generateMigrationFile(['THIS IS NOT VALID SQL']);
        require $migrationFile->filePath;

        // the migration body fails, but the start marker must persist so the failure is detected later
        try {
            $service->executeMigration($migrationFile->version, MigrationPhase::BEFORE);
            self::fail('Expected the migration body to fail');
        } catch (Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $incomplete = $service->getIncompleteMigrations();
        self::assertCount(1, $incomplete);
        self::assertSame('failing1', $incomplete[0]['version']);
        self::assertSame('before', $incomplete[0]['phase']);

        // and the next run must refuse to continue
        self::expectException(IncompleteMigrationException::class);
        $service->assertNoIncompleteMigrations();
    }

    public function testMigrationTableIsDetectedDespiteSchemaAssetsFilter(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);
        $connection = $entityManager->getConnection();
        $migrationTableName = $service->getConfig()->getMigrationTableName();

        // applications commonly hide the migration table from schema listings to keep it out of ORM diffs
        $connection->getConfiguration()->setSchemaAssetsFilter(
            static fn (string $tableName): bool => $tableName !== $migrationTableName,
        );

        $connection->executeStatement(
            "CREATE TABLE {$migrationTableName} (version VARCHAR(20) NOT NULL, phase VARCHAR(10) NOT NULL, "
                . 'started_at VARCHAR(30) NOT NULL, finished_at VARCHAR(30) NOT NULL, PRIMARY KEY (version, phase))',
        );

        // must upgrade the hidden table instead of attempting to create it again
        self::assertSame(MigrationTableState::Upgraded, $service->initializeMigrationTable());
        self::assertSame(MigrationTableState::AlreadyUpToDate, $service->initializeMigrationTable());

        $service->assertMigrationTableUpToDate();
    }

    public function testMigrationTableIsCreatedDespiteSchemaAssetsFilter(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);
        $migrationTableName = $service->getConfig()->getMigrationTableName();

        $entityManager->getConnection()->getConfiguration()->setSchemaAssetsFilter(
            static fn (string $tableName): bool => $tableName !== $migrationTableName,
        );

        self::assertSame(MigrationTableState::Created, $service->initializeMigrationTable());
        self::assertSame(MigrationTableState::AlreadyUpToDate, $service->initializeMigrationTable());

        $service->assertMigrationTableUpToDate();
    }

    public function testAssertMigrationTableUpToDateThrowsWhenTableMissing(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);

        try {
            $service->assertMigrationTableUpToDate();
            self::fail('Expected MigrationTableNotInitializedException');
        } catch (MigrationTableNotInitializedException $e) {
            self::assertSame('migration', $e->tableName);
            self::assertStringContainsString('migration:init', $e->getMessage());
        }
    }

    public function testAssertMigrationTableUpToDateThrowsOnOutdatedSchema(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);
        $connection = $entityManager->getConnection();
        $migrationTableName = $service->getConfig()->getMigrationTableName();

        // pre-2.0 schema with finished_at NOT NULL
        $connection->executeStatement(
            "CREATE TABLE {$migrationTableName} (version VARCHAR(20) NOT NULL, phase VARCHAR(10) NOT NULL, "
                . 'started_at VARCHAR(30) NOT NULL, finished_at VARCHAR(30) NOT NULL, PRIMARY KEY (version, phase))',
        );

        try {
            $service->assertMigrationTableUpToDate();
            self::fail('Expected MigrationTableNotInitializedException');
        } catch (MigrationTableNotInitializedException $e) {
            self::assertStringContainsString('out of date', $e->getMessage());
        }

        // after init upgrades the schema, the assertion passes
        $service->initializeMigrationTable();
        $service->assertMigrationTableUpToDate();
    }

    public function testAssertMigrationTableUpToDateThrowsWhenFinishedAtColumnMissing(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);
        $connection = $entityManager->getConnection();
        $migrationTableName = $service->getConfig()->getMigrationTableName();

        // table exists but has no finished_at column at all
        $connection->executeStatement(
            "CREATE TABLE {$migrationTableName} (version VARCHAR(20) NOT NULL, phase VARCHAR(10) NOT NULL, "
                . 'started_at VARCHAR(30) NOT NULL, PRIMARY KEY (version, phase))',
        );

        try {
            $service->assertMigrationTableUpToDate();
            self::fail('Expected MigrationTableNotInitializedException');
        } catch (MigrationTableNotInitializedException $e) {
            self::assertStringContainsString('out of date', $e->getMessage());
            self::assertStringContainsString('migration:init', $e->getMessage());
        }
    }

    public function testReadsHonorCustomMigrationTableName(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager, migrationTableName: 'custom_migration_table');
        $connection = $entityManager->getConnection();

        self::assertSame('custom_migration_table', $service->getConfig()->getMigrationTableName());

        $service->initializeMigrationTable();
        self::assertTrue($connection->createSchemaManager()->tablesExist(['custom_migration_table']));

        $connection->insert('custom_migration_table', ['version' => 'v1', 'phase' => 'before', 'started_at' => '2023-01-01 00:00:00.000000', 'finished_at' => '2023-01-01 00:00:01.000000']);
        $connection->insert('custom_migration_table', ['version' => 'v2', 'phase' => 'after', 'started_at' => '2023-01-02 00:00:00.000000', 'finished_at' => null]);

        self::assertSame(['v1' => 'v1'], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertSame([['version' => 'v2', 'phase' => 'after', 'startedAt' => '2023-01-02 00:00:00.000000']], $service->getIncompleteMigrations());
    }

    public function testAcquireAndReleaseLockDoesNotThrow(): void
    {
        [$entityManager, $logger] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);
        $service->initializeMigrationTable();
        $logger->clean();

        $service->acquireLock();
        $service->releaseLock();

        if ($entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::assertSame([], $logger->getQueriesPerformed()); // SQLite locking is a no-op
        } else {
            self::assertNotSame([], $logger->getQueriesPerformed()); // MySQL/PostgreSQL ran real lock SQL
        }
    }

    public function testLockPreventsConcurrentAcquire(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();

        if ($entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite locking is a no-op (writers are serialized at the filesystem level)');
        }

        // a second, independent connection/session to the same database
        [$entityManager2] = $this->createEntityManagerAndLogger();

        $serviceA = $this->createMigrationService($entityManager);
        $serviceB = $this->createMigrationService($entityManager2, lockTimeoutSeconds: 1);

        $serviceA->acquireLock();

        try {
            $serviceB->acquireLock();
            self::fail('Expected MigrationLockException, the lock is held by another session');
        } catch (MigrationLockException $e) {
            self::assertSame(1, $e->timeoutSeconds);
        } finally {
            $serviceA->releaseLock();
        }

        // once released, the lock can be acquired again
        $serviceB->acquireLock();
        $serviceB->releaseLock();
    }

    public function testGetPreparedVersions(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager, []);
        self::assertSame([], $service->getPreparedVersions());

        touch($this->getMigrationsTestDir() . '/' . $service->getConfig()->getMigrationClassPrefix() . 'fakeversion.php');
        touch($this->getMigrationsTestDir() . '/' . $service->getConfig()->getMigrationClassPrefix() . 'ignored.extension');
        touch($this->getMigrationsTestDir() . '/InvalidClassPrefix.php');

        self::assertSame(['fakeversion' => 'fakeversion'], $service->getPreparedVersions());
    }

    public function testExcludedTables(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager, []);

        $entityManager->getConnection()->executeQuery('CREATE TABLE excluded (id INT)');

        self::assertEquals([
            'CREATE TABLE entity (id VARCHAR(255) NOT NULL, PRIMARY KEY (id))',
            'DROP TABLE excluded',
        ], $service->generateDiffSqls());

        $service = $this->createMigrationService($entityManager, ['excluded']);

        self::assertEquals([
            'CREATE TABLE entity (id VARCHAR(255) NOT NULL, PRIMARY KEY (id))',
        ], $service->generateDiffSqls());

        // cannot create excluded table even when defined in metadata - it would always fail in migration:check
        $service = $this->createMigrationService($entityManager, ['excluded', 'entity']);

        self::assertEquals([], $service->generateDiffSqls());
    }

    /**
     * @param string[] $excludedTables
     */
    private function createMigrationService(
        EntityManagerInterface $entityManager,
        array $excludedTables = [],
        bool $transactional = false,
        ?MigrationVersionProvider $versionProvider = null,
        ?MigrationAnalyzer $statementAnalyzer = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?string $migrationTableName = null,
        ?int $lockTimeoutSeconds = null,
    ): MigrationService
    {
        $migrationsDir = $this->getMigrationsTestDir();

        if (is_dir($migrationsDir)) {
            $filesToDelete = glob("$migrationsDir/*.*");

            if ($filesToDelete === false) {
                throw new LogicException("Failed to glob $migrationsDir");
            }

            array_map('unlink', $filesToDelete);
            rmdir($migrationsDir);
        }

        mkdir($migrationsDir);

        return new MigrationService(
            $entityManager,
            new MigrationConfig(
                $migrationsDir,
                $migrationTableName,
                null,
                null,
                $excludedTables,
                $transactional
                    ? __DIR__ . '/templates/transactional.txt'
                    : __DIR__ . '/templates/non-transactional.txt',
                null,
                $lockTimeoutSeconds,
            ),
            null,
            $versionProvider,
            $statementAnalyzer,
            $eventDispatcher,
        );
    }

    private function getMigrationsTestDir(): string
    {
        return __DIR__ . '/../tmp/migrations';
    }

    private function createPhaseRoutingAnalyzer(): MigrationAnalyzer
    {
        return new class implements MigrationAnalyzer
        {

            /**
             * @param list<string> $statements
             * @return list<Statement>
             */
            public function analyze(array $statements): array
            {
                $result = [];

                foreach ($statements as $statement) {
                    if (str_contains($statement, '2')) {
                        $result[] = new Statement($statement, MigrationPhase::AFTER);
                    } else {
                        $result[] = new Statement($statement, MigrationPhase::BEFORE);
                    }
                }

                return $result;
            }

        };
    }

}
