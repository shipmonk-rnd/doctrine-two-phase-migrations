<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use PHPUnit\Framework\TestCase;

class DefaultMigrationGeneratorTest extends TestCase
{

    public function testGenerateContent(): void
    {
        $generator = new DefaultMigrationGenerator(
            __DIR__ . '/../src/template/migration.txt',
            '        ',
        );

        $statements = [
            new Statement('CREATE TABLE users', MigrationPhase::BEFORE),
            new Statement('ALTER TABLE users ADD COLUMN name VARCHAR(255)', MigrationPhase::BEFORE),
            new Statement('CREATE INDEX idx_name ON users(name)', MigrationPhase::AFTER),
        ];

        $content = $generator->generate('Migration20250101000000', 'TestNamespace', $statements);

        self::assertStringContainsString('namespace TestNamespace;', $content);
        self::assertStringContainsString('class Migration20250101000000 implements Migration', $content);
        self::assertStringContainsString("\$executor->executeQuery('CREATE TABLE users');", $content);
        self::assertStringContainsString("\$executor->executeQuery('ALTER TABLE users ADD COLUMN name VARCHAR(255)');", $content);
        self::assertStringContainsString("\$executor->executeQuery('CREATE INDEX idx_name ON users(name)');", $content);
    }

}
