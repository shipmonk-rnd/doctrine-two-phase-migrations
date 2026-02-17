<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

class MigrationDefaultAnalyzer implements MigrationAnalyzer
{

    /**
     * @param list<string> $statements
     * @return list<Statement>
     */
    public function analyze(array $statements): array
    {
        $result = [];

        foreach ($statements as $statement) {
            $result[] = new Statement($statement, MigrationPhase::BEFORE);
        }

        return $result;
    }

}
