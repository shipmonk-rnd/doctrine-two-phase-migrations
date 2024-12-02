<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

/**
 * Analyzer can:
 * - sort statements into before/after phases
 * - modify statements (e.g. adding comment or algorithm/lock clause)
 * - add new statements (e.g. turn on/off foreign key checks before/after adding a new FK)
 *
 * Analyzer should not:
 * - drop statements
 * - reorder statements
 */
interface MigrationsAnalyzer
{

    /**
     * @param list<string|Statement> $statements
     * @return list<Statement>
     */
    public function analyze(array $statements): array;

}
