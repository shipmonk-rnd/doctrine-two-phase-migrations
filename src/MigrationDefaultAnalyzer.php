<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

class MigrationDefaultAnalyzer implements MigrationAnalyzer
{

    /**
     * @param list<string|Statement> $statements
     * @return list<Statement>
     */
    public function analyze(array $statements): array
    {
        $result = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Statement) {
                $result[] = $statement;
            } else {
                $result[] = new Statement($statement, MigrationPhase::BEFORE);
            }
        }

        return $result;
    }

}
