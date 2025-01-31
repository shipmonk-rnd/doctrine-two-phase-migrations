<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use LogicException;
use function is_dir;
use function is_file;

class MigrationConfig
{

    private string $migrationsDir;

    private string $migrationsTableName;

    private string $migrationClassNamespace;

    private string $migrationClassPrefix;

    /**
     * @var string[]
     */
    private array $excludedTables;

    private string $templateFilePath;

    private string $templateIndent;

    /**
     * @param string[]|null $excludedTables
     */
    public function __construct(
        string $migrationsDir,
        ?string $migrationTableName = null,
        ?string $migrationClassNamespace = null,
        ?string $migrationClassPrefix = null,
        ?array $excludedTables = null,
        ?string $templateFilePath = null,
        ?string $templateIndent = null,
    )
    {
        $templateFilePathToUse = $templateFilePath ?? __DIR__ . '/template/migration.txt';

        if (!is_dir($migrationsDir)) {
            throw new LogicException("Given migration directory $migrationsDir is not a directory");
        }

        if (!is_file($templateFilePathToUse)) {
            throw new LogicException("Template file $templateFilePathToUse is not a file");
        }

        $this->migrationsDir = $migrationsDir;
        $this->migrationsTableName = $migrationTableName ?? 'migration';
        $this->migrationClassNamespace = $migrationClassNamespace ?? 'Migrations';
        $this->migrationClassPrefix = $migrationClassPrefix ?? 'Migration';
        $this->excludedTables = $excludedTables ?? [];
        $this->excludedTables[] = $this->getMigrationTableName();
        $this->templateFilePath = $templateFilePathToUse;
        $this->templateIndent = $templateIndent ?? '        ';
    }

    public function getMigrationsDirectory(): string
    {
        return $this->migrationsDir;
    }

    public function getMigrationClassNamespace(): string
    {
        return $this->migrationClassNamespace;
    }

    /**
     * @return string[]
     */
    public function getExcludedTables(): array
    {
        return $this->excludedTables;
    }

    public function getMigrationClassPrefix(): string
    {
        return $this->migrationClassPrefix;
    }

    public function getMigrationTableName(): string
    {
        return $this->migrationsTableName;
    }

    public function getTemplateFilePath(): string
    {
        return $this->templateFilePath;
    }

    public function getTemplateIndent(): string
    {
        return $this->templateIndent;
    }

}
