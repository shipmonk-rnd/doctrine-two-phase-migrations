<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use LogicException;
use function array_map;
use function file_get_contents;
use function implode;
use function str_replace;
use const PHP_EOL;

class DefaultMigrationGenerator implements MigrationGenerator
{

    private string $templateFilePath;

    private string $templateIndent;

    public function __construct(
        string $templateFilePath,
        string $templateIndent,
    )
    {
        $this->templateFilePath = $templateFilePath;
        $this->templateIndent = $templateIndent;
    }

    public function generate(
        string $className,
        string $namespace,
        array $statements,
    ): string
    {
        $statementsBefore = [];
        $statementsAfter = [];

        foreach ($statements as $statement) {
            if ($statement->phase === MigrationPhase::AFTER) {
                $statementsAfter[] = $statement;
            } else {
                $statementsBefore[] = $statement;
            }
        }

        $beforeSqls = array_map(static fn (Statement $statement) => "\$executor->executeQuery('" . str_replace("'", "\'", $statement->sql) . "');", $statementsBefore);
        $afterSqls = array_map(static fn (Statement $statement) => "\$executor->executeQuery('" . str_replace("'", "\'", $statement->sql) . "');", $statementsAfter);

        return $this->generateMigrationContent($className, $namespace, $beforeSqls, $afterSqls);
    }

    /**
     * @param list<string> $statementsBefore
     * @param list<string> $statementsAfter
     */
    private function generateMigrationContent(
        string $className,
        string $namespace,
        array $statementsBefore,
        array $statementsAfter,
    ): string
    {
        $template = file_get_contents($this->templateFilePath);

        if ($template === false) {
            throw new LogicException("Unable to read {$this->templateFilePath}");
        }

        $template = str_replace('%namespace%', $namespace, $template);
        $template = str_replace('%className%', $className, $template);
        $template = str_replace('%statements%', implode(PHP_EOL . $this->templateIndent, $statementsBefore), $template);
        $template = str_replace('%statementsAfter%', implode(PHP_EOL . $this->templateIndent, $statementsAfter), $template);

        return $template;
    }

}
