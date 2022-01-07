<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Nette\Utils\Finder;
use SplFileInfo;
use function date;
use function ksort;
use function str_replace;

class MigrationService
{

    private Connection $connection;

    private string $migrationsDir;

    private string $migrationClassNamespace;

    private string $migrationClassPrefix;

    private bool $includeDropTableInDatabaseSync;

    public function __construct(
        Connection $connection,
        string $migrationsDir,
        string $migrationClassNamespace = 'Migrations',
        string $migrationClassPrefix = 'Migration',
        bool $includeDropTableInDatabaseSync = true,
    )
    {
        $this->connection = $connection;
        $this->migrationsDir = $migrationsDir;
        $this->migrationClassNamespace = $migrationClassNamespace;
        $this->migrationClassPrefix = $migrationClassPrefix;
        $this->includeDropTableInDatabaseSync = $includeDropTableInDatabaseSync;
    }

    public function getMigrationsDir(): string
    {
        return $this->migrationsDir;
    }

    public function getMigrationClassNamespace(): string
    {
        return $this->migrationClassNamespace;
    }

    public function shouldIncludeDropTableInDatabaseSync(): bool
    {
        return $this->includeDropTableInDatabaseSync;
    }

    public function getMigrationClassPrefix(): string
    {
        return $this->migrationClassPrefix;
    }

    public function getMigration(string $version): Migration
    {
        /** @var class-string<Migration> $fqn */
        $fqn = '\\' . $this->getMigrationClassNamespace() . '\\' . $this->getMigrationClassPrefix() . $version;
        return new $fqn();
    }

    /**
     * @return array<string, string>
     */
    public function getPreparedVersions(): array
    {
        $migrations = [];

        /** @var SplFileInfo $fileinfo */
        foreach (Finder::findFiles($this->getMigrationClassPrefix() . '*.php')->in($this->migrationsDir) as $fileinfo) {
            $version = str_replace($this->getMigrationClassPrefix(), '', $fileinfo->getBasename('.php'));
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
        $result = $this->connection->fetchAllAssociative(
            'SELECT version FROM migration WHERE phase = :phase',
            [
                'phase' => $phase,
            ],
        );

        $versions = [];

        foreach ($result as $row) {
            /** @var string $version */
            $version = $row['version'];
            $versions[$version] = $version;
        }

        ksort($versions);

        return $versions;
    }

    public function markMigrationExecuted(string $version, string $phase): void
    {
        $this->connection->insert('migration', [
            'version' => $version,
            'phase' => $phase,
            'executed' => date('Y-m-d H:i:s'),
        ]);
    }

    public function initializeMigrationTable(): void
    {
        $schema = new Schema();
        $table = $schema->createTable('migration');
        $table->addColumn('version', 'string', ['length' => 14]);
        $table->addColumn('phase', 'string', ['length' => 6]);
        $table->addColumn('executed', 'datetime');
        $table->setPrimaryKey(['version', 'phase']);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeQuery($sql);
        }
    }

}
