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

        self::assertSame([], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertSame([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertSame([], $service->getPreparedVersions());

        $generatedFile = $service->generateMigrationFile($sqls);
        $generatedVersion = $generatedFile->getVersion();
        $generatedContents = FileSystem::read($generatedFile->getFilePath());

        require $generatedFile->getFilePath();

        foreach ($sqls as $sql) {
            self::assertStringContainsString($sql, $generatedContents);
        }

        self::assertSame([], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertSame([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertSame([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertCount(0, $connection->executeQuery("SELECT * FROM {$migrationTableName}")->fetchAll());

        $service->executeMigration($generatedVersion, MigrationPhase::BEFORE);

        self::assertSame([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertSame([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertSame([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertCount(1, $connection->executeQuery("SELECT * FROM {$migrationTableName}")->fetchAll());

        $service->executeMigration($generatedVersion, MigrationPhase::AFTER);

        self::assertSame([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertSame([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertSame([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertCount(2, $connection->executeQuery("SELECT * FROM {$migrationTableName}")->fetchAll());

        $sqls2 = $service->generateDiffSqls();

        self::assertSame([], $sqls2); // no diff after migration
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

    private function createMigrationService(EntityManagerInterface $entityManager): MigrationService
    {
        $migrationsDir = __DIR__ . '/../../../tmp/migrations';
        FileSystem::delete($migrationsDir);
        FileSystem::createDir($migrationsDir);

        return new MigrationService($entityManager, $migrationsDir);
    }

}
