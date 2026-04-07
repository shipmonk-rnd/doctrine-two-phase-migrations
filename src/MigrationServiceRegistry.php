<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use LogicException;
use function array_keys;
use function count;
use function implode;
use function reset;

class MigrationServiceRegistry
{

    /**
     * @var array<string, MigrationService>
     */
    private array $services;

    /**
     * @param array<string, MigrationService> $services
     */
    public function __construct(array $services)
    {
        if (count($services) === 0) {
            throw new LogicException('At least one migration service must be registered');
        }

        $this->services = $services;
    }

    public function get(?string $namespace = null): MigrationService
    {
        if ($namespace === null) {
            if (count($this->services) === 1) {
                return reset($this->services);
            }

            throw new LogicException(
                'Multiple migration namespaces are registered, you must specify which one to use via --namespace option. '
                . 'Available namespaces: ' . implode(', ', array_keys($this->services)),
            );
        }

        if (!isset($this->services[$namespace])) {
            throw new LogicException(
                "Migration namespace '{$namespace}' is not registered. "
                . 'Available namespaces: ' . implode(', ', array_keys($this->services)),
            );
        }

        return $this->services[$namespace];
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->services);
    }

    public function isSingleNamespace(): bool
    {
        return count($this->services) === 1;
    }

}
