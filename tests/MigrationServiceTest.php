<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionStartedEvent;
use ShipMonk\Doctrine\Migration\Event\MigrationExecutionSucceededEvent;
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

        self::assertTrue($initialized1);
        self::assertFalse($initialized2);
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
            'Beginning transaction',
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
            'Committing transaction',
        ], $logger->getQueriesPerformed());

        $logger->clean();

        $nonTransactionalService->executeMigration($nonTransactionalMigrationFile->version, MigrationPhase::BEFORE);

        self::assertSame([
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
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
            'SELECT 1',
            'SELECT 3',
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
        ], $logger->getQueriesPerformed());

        $logger->clean();

        $migrationsService->executeMigration($migrationFile->version, MigrationPhase::AFTER);

        self::assertSame([
            'SELECT 2',
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
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
            'SELECT 1',
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
        ], $logger->getQueriesPerformed());

        $logger->clean();

        $migrationsService->executeMigration($migrationFile->version, MigrationPhase::AFTER);

        self::assertSame([
            'SELECT 2',
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
        ], $logger->getQueriesPerformed());
    }

    public function testInitialization(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $service = $this->createMigrationService($entityManager);

        self::assertTrue($service->initializeMigrationTable());

        $migrationTableName = $service->getConfig()->getMigrationTableName();
        $schemaManager = $entityManager->getConnection()->createSchemaManager();

        $table = $schemaManager->introspectTable($migrationTableName);

        self::assertTrue($table->hasColumn('version'));
        self::assertTrue($table->hasColumn('phase'));
        self::assertTrue($table->hasColumn('started_at'));
        self::assertTrue($table->hasColumn('finished_at'));
        self::assertNotNull($table->getPrimaryKeyConstraint());
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
                null,
                null,
                null,
                $excludedTables,
                $transactional
                    ? __DIR__ . '/templates/transactional.txt'
                    : __DIR__ . '/templates/non-transactional.txt',
                null,
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
