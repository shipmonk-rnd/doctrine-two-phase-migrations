<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use LogicException;
use PHPUnit\Framework\TestCase;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class DefaultMigrationGeneratorTest extends TestCase
{

    public function testGenerateContent(): void
    {
        $generator = new DefaultMigrationGenerator(
            __DIR__ . '/../src/template/migration.txt',
            '        ',
        );

        $statements = [
            new Statement('CREATE TABLE users', null),
            new Statement('ALTER TABLE users ADD COLUMN name VARCHAR(255)', MigrationPhase::BEFORE),
            new Statement('CREATE INDEX idx_name ON users(name)', MigrationPhase::AFTER),
        ];

        $content = $generator->generate('Migration20250101000000', 'TestNamespace', $statements);

        self::assertStringContainsString('namespace TestNamespace;', $content);
        self::assertStringContainsString('class Migration20250101000000 implements Migration', $content);

        [$beforeBody, $afterBody] = $this->splitPhaseBodies($content);

        self::assertStringContainsString("\$executor->executeQuery('CREATE TABLE users');", $beforeBody);
        self::assertStringContainsString("\$executor->executeQuery('ALTER TABLE users ADD COLUMN name VARCHAR(255)');", $beforeBody);
        self::assertStringContainsString("\$executor->executeQuery('CREATE INDEX idx_name ON users(name)');", $afterBody);
        self::assertStringNotContainsString('CREATE INDEX', $beforeBody);
    }

    public function testGenerateFailsWhenTemplateLacksPlaceholderForNonEmptyBucket(): void
    {
        $templatePath = sys_get_temp_dir() . '/migration-template-' . uniqid('', true) . '.txt';
        $originalTemplate = file_get_contents(__DIR__ . '/../src/template/migration.txt');
        self::assertNotFalse($originalTemplate);
        file_put_contents($templatePath, str_replace('%statementsBefore%', '', $originalTemplate));

        $generator = new DefaultMigrationGenerator($templatePath, '        ');

        try {
            $generator->generate('Migration20250101000000', 'TestNamespace', [
                new Statement('CREATE TABLE users', MigrationPhase::BEFORE),
            ]);
            self::fail('Expected LogicException');

        } catch (LogicException $e) {
            self::assertStringContainsString('%statementsBefore%', $e->getMessage());

        } finally {
            unlink($templatePath);
        }
    }

    /**
     * @return array{string, string}
     */
    private function splitPhaseBodies(string $content): array
    {
        $parts = explode('public function after(', $content);
        self::assertCount(2, $parts);

        return [$parts[0], $parts[1]];
    }

}
