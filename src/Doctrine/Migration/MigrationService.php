<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use LogicException;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use SplFileInfo;
use function date;
use function implode;
use function ksort;
use function sprintf;
use function str_replace;
use function strlen;

class MigrationService
{

    private Connection $connection;

    private string $migrationsDir;

    private string $migrationClassNamespace;

    private string $migrationClassPrefix;

    private bool $includeDropTableInDatabaseSync;

    private string $templateFilePath;

    private string $templateIndent;

    public function __construct(
        Connection $connection,
        string $migrationsDir,
        string $migrationClassNamespace = 'Migrations',
        string $migrationClassPrefix = 'Migration',
        bool $includeDropTableInDatabaseSync = true,
        string $templateFilePath = __DIR__ . '/template/migration.txt',
        string $templateIndent = '        '
    )
    {
        $this->connection = $connection;
        $this->migrationsDir = $migrationsDir;
        $this->migrationClassNamespace = $migrationClassNamespace;
        $this->migrationClassPrefix = $migrationClassPrefix;
        $this->includeDropTableInDatabaseSync = $includeDropTableInDatabaseSync;
        $this->templateFilePath = $templateFilePath;
        $this->templateIndent = $templateIndent;
    }

    public function getMigrationsDir(): string
    {
        return $this->migrationsDir;
    }

    private function getMigrationClassNamespace(): string
    {
        return $this->migrationClassNamespace;
    }

    public function shouldIncludeDropTableInDatabaseSync(): bool
    {
        return $this->includeDropTableInDatabaseSync;
    }

    private function getMigrationClassPrefix(): string
    {
        return $this->migrationClassPrefix;
    }

    private function getMigration(string $version): Migration
    {
        /** @var class-string<Migration> $fqn */
        $fqn = '\\' . $this->getMigrationClassNamespace() . '\\' . $this->getMigrationClassPrefix() . $version;
        return new $fqn();
    }

    public function executeMigration(string $version, string $phase): void
    {
        $migration = $this->getMigration($version);

        if ($phase === MigrationPhase::BEFORE) {
            $migration->before($this->connection);

        } elseif ($phase === MigrationPhase::AFTER) {
            $migration->after($this->connection);

        } else {
            throw new LogicException('Invalid phase given!');
        }

        $this->markMigrationExecuted($version, $phase, new DateTimeImmutable());
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
        /** @var array<array{ version: string }> $result */
        $result = $this->connection->executeQuery(
            'SELECT version FROM migration WHERE phase = :phase',
            [
                'phase' => $phase,
            ],
        )->fetchAll();

        $versions = [];

        foreach ($result as $row) {
            $version = $row['version'];
            $versions[$version] = $version;
        }

        ksort($versions);

        return $versions;
    }

    public function markMigrationExecuted(string $version, string $phase, DateTimeImmutable $executedAt): void
    {
        $this->connection->insert('migration', [
            'version' => $version,
            'phase' => $phase,
            'executed' => $executedAt,
        ], [
            'executed' => Types::DATETIMETZ_IMMUTABLE,
        ]);
    }

    public function initializeMigrationTable(): void
    {
        $schema = new Schema();
        $table = $schema->createTable('migration');
        $table->addColumn('version', Types::STRING, ['length' => strlen($this->getNextVersion())]);
        $table->addColumn('phase', Types::STRING, ['length' => 6]);
        $table->addColumn('executed', Types::DATETIMETZ_IMMUTABLE);
        $table->setPrimaryKey(['version', 'phase']);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeQuery($sql);
        }
    }

    /**
     * @param string[] $sqls
     */
    public function generateMigrationFile(array $sqls): MigrationFile
    {
        $statements = [];

        foreach ($sqls as $sql) {
            $statements[] = sprintf("\$connection->executeQuery('%s');", str_replace("'", "\'", $sql));
        }

        $migrationsDir = $this->getMigrationsDir();
        $migrationClassPrefix = $this->getMigrationClassPrefix();
        $migrationClassNamespace = $this->getMigrationClassNamespace();

        $version = $this->getNextVersion();
        $template = FileSystem::read($this->templateFilePath);
        $template = str_replace('%namespace%', $migrationClassNamespace, $template);
        $template = str_replace('%version%', $version, $template);
        $template = str_replace('%statements%', implode("\n" . $this->templateIndent, $statements), $template);

        $filePath = $migrationsDir . '/' . $migrationClassPrefix . $version . '.php';
        FileSystem::createDir($migrationsDir);
        FileSystem::write($filePath, $template);

        return new MigrationFile($filePath, $version);
    }

    private function getNextVersion(): string
    {
        return date('YmdHis');
    }

}
