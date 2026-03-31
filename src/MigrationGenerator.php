<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

interface MigrationGenerator
{

    /**
     * @param list<Statement> $statements
     */
    public function generate(
        string $className,
        string $namespace,
        array $statements,
    ): string;

}
