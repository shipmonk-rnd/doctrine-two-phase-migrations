<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\ORM\EntityManagerInterface;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

class MigrationServiceTest extends TestCase
{

    use WithEntityManagerTest;

    public function testInitGenerationExecution(): void
    {
        $entityManager = $this->createEntityManager();
        $connection = $entityManager->getConnection();
        $service = $this->createMigrationService($entityManager);

        $migrationTableName = $service->getMigrationTableName();

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
        $generatedContents = FileSystem::read($generatedFile->getFilePath());

        require $generatedFile->getFilePath();

        foreach ($sqls as $sql) {
            self::assertStringContainsString($sql, $generatedContents);
        }

        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertEquals([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertCount(0, $connection->executeQuery("SELECT * FROM {$migrationTableName}")->fetchAll());

        $service->executeMigration($generatedVersion, MigrationPhase::BEFORE);

        self::assertEquals([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertEquals([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertEquals([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertCount(1, $connection->executeQuery("SELECT * FROM {$migrationTableName}")->fetchAll());

        $service->executeMigration($generatedVersion, MigrationPhase::AFTER);

        self::assertEquals([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertEquals([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertEquals([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertCount(2, $connection->executeQuery("SELECT * FROM {$migrationTableName}")->fetchAll());

        $sqls2 = $service->generateDiffSqls();

        self::assertEquals([], $sqls2); // no diff after migration
    }

    public function testInitialization(): void
    {
        $entityManager = $this->createEntityManager();
        $service = $this->createMigrationService($entityManager);

        self::assertTrue($service->initializeMigrationTable());

        $migrationTableName = $service->getMigrationTableName();
        $table = $entityManager->getConnection()->getSchemaManager()->listTableDetails($migrationTableName);

        self::assertTrue($table->hasColumn('version'));
        self::assertTrue($table->hasColumn('phase'));
        self::assertTrue($table->hasColumn('executed'));
        self::assertTrue($table->hasPrimaryKey());
    }

    public function testExcludedTables(): void
    {
        $entityManager = $this->createEntityManager();
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
    private function createMigrationService(EntityManagerInterface $entityManager, array $excludedTables = []): MigrationService
    {
        $migrationsDir = __DIR__ . '/../../../tmp/migrations';
        FileSystem::delete($migrationsDir);
        FileSystem::createDir($migrationsDir);

        return new MigrationService($entityManager, null, $migrationsDir, 'Migrations', 'Migration', $excludedTables);
    }

}
