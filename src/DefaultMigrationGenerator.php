<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use LogicException;
use function array_map;
use function file_get_contents;
use function implode;
use function str_contains;
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
        $statementsUndecided = [];
        $statementsBefore = [];
        $statementsAfter = [];

        foreach ($statements as $statement) {
            match ($statement->phase) {
                null => $statementsUndecided[] = $statement,
                MigrationPhase::BEFORE => $statementsBefore[] = $statement,
                MigrationPhase::AFTER => $statementsAfter[] = $statement,
            };
        }

        $toSql = static fn (Statement $statement) => "\$executor->executeQuery('" . str_replace("'", "\'", $statement->sql) . "');";

        return $this->generateMigrationContent(
            $className,
            $namespace,
            array_map($toSql, $statementsUndecided),
            array_map($toSql, $statementsBefore),
            array_map($toSql, $statementsAfter),
        );
    }

    /**
     * @param list<string> $statementsUndecided
     * @param list<string> $statementsBefore
     * @param list<string> $statementsAfter
     */
    private function generateMigrationContent(
        string $className,
        string $namespace,
        array $statementsUndecided,
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
        $template = $this->replaceStatementsPlaceholder($template, '%statements%', $statementsUndecided);
        $template = $this->replaceStatementsPlaceholder($template, '%statementsBefore%', $statementsBefore);
        $template = $this->replaceStatementsPlaceholder($template, '%statementsAfter%', $statementsAfter);

        return $template;
    }

    /**
     * @param list<string> $statements
     */
    private function replaceStatementsPlaceholder(
        string $template,
        string $placeholder,
        array $statements,
    ): string
    {
        if (!str_contains($template, $placeholder)) {
            if ($statements !== []) {
                throw new LogicException("Missing {$placeholder} placeholder in {$this->templateFilePath}, generated migration would lose statements.");
            }

            return $template;
        }

        return str_replace($placeholder, implode(PHP_EOL . $this->templateIndent, $statements), $template);
    }

}
