<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use function array_map;
use function file_get_contents;
use function glob;
use function is_dir;
use function is_string;
use function mkdir;
use function rmdir;
use function str_contains;
use function touch;

class MigrationServiceTest extends TestCase
{

    use WithEntityManagerTestCase;

    public function testInitGenerationExecution(): void
    {
        [$entityManager] = $this->createEntityManagerAndLogger();
        $connection = $entityManager->getConnection();
        $service = $this->createMigrationService($entityManager);

        $migrationTableName = $service->getConfig()->getMigrationTableName();

        $initialized1 = $service->initializeMigrationTable();
        $initialized2 = $service->initializeMigrationTable(); // double init should not fail

        $sqls = $service->generateDiffSqls();

        self::assertTrue($initialized1);
        self::assertFalse($initialized2);
        self::assertSame(['CREATE TABLE entity (id VARCHAR(255) NOT NULL, PRIMARY KEY(id))'], $sqls);

        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertEquals([], $service->getPreparedVersions());

        $generatedFile = $service->generateMigrationFile($sqls);
        $generatedVersion = $generatedFile->getVersion();
        $generatedContents = file_get_contents($generatedFile->getFilePath());

        self::assertNotFalse($generatedContents);

        require $generatedFile->getFilePath();

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

        require $transactionalMigrationFile->getFilePath();
        require $nonTransactionalMigrationFile->getFilePath();

        $transactionalService->executeMigration($transactionalMigrationFile->getVersion(), MigrationPhase::BEFORE);

        self::assertSame([
            'Beginning transaction',
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
            'Committing transaction',
        ], $logger->getQueriesPerformed());

        $logger->clean();

        $nonTransactionalService->executeMigration($nonTransactionalMigrationFile->getVersion(), MigrationPhase::BEFORE);

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

        $migrationsService = $this->createMigrationService($entityManager, [], false, $versionProvider);

        $migrationsService->initializeMigrationTable();
        $logger->clean();

        $migrationFile = $migrationsService->generateMigrationFile([
            new Statement('SELECT 1', MigrationPhase::BEFORE),
            new Statement('SELECT 2', MigrationPhase::AFTER),
            new Statement('SELECT 3'), // no phase defaults to BEFORE
        ]);

        require $migrationFile->getFilePath();

        $migrationsService->executeMigration($migrationFile->getVersion(), MigrationPhase::BEFORE);

        self::assertSame([
            'SELECT 1',
            'SELECT 3',
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
        ], $logger->getQueriesPerformed());

        $logger->clean();

        $migrationsService->executeMigration($migrationFile->getVersion(), MigrationPhase::AFTER);

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

        $statementAnalyser = new class implements MigrationAnalyzer
        {

            /**
             * @param list<string|Statement> $statements
             * @return list<Statement>
             */
            public function analyze(array $statements): array
            {
                $result = [];

                foreach ($statements as $statement) {
                    if (is_string($statement) && str_contains($statement, '2')) {
                        $result[] = new Statement($statement, MigrationPhase::AFTER);
                    } elseif (is_string($statement)) {
                        $result[] = new Statement($statement, MigrationPhase::BEFORE);
                    } else {
                        $result[] = $statement;
                    }
                }

                return $result;
            }

        };

        $migrationsService = $this->createMigrationService($entityManager, [], false, $versionProvider, $statementAnalyser);

        $migrationsService->initializeMigrationTable();
        $logger->clean();

        $migrationFile = $migrationsService->generateMigrationFile(['SELECT 1', 'SELECT 2']);

        require $migrationFile->getFilePath();

        $migrationsService->executeMigration($migrationFile->getVersion(), MigrationPhase::BEFORE);

        self::assertSame([
            'SELECT 1',
            'INSERT INTO migration (version, phase, started_at, finished_at) VALUES (?, ?, ?, ?)',
        ], $logger->getQueriesPerformed());

        $logger->clean();

        $migrationsService->executeMigration($migrationFile->getVersion(), MigrationPhase::AFTER);

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
        self::assertNotNull($table->getPrimaryKey());
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
            'CREATE TABLE entity (id VARCHAR(255) NOT NULL, PRIMARY KEY(id))',
            'DROP TABLE excluded',
        ], $service->generateDiffSqls());

        $service = $this->createMigrationService($entityManager, ['excluded']);

        self::assertEquals([
            'CREATE TABLE entity (id VARCHAR(255) NOT NULL, PRIMARY KEY(id))',
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
        ?MigrationAnalyzer $statementAnalyzer = null
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
        );
    }

    private function getMigrationsTestDir(): string
    {
        return __DIR__ . '/../tmp/migrations';
    }

}
