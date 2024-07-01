<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Logging\Driver as LoggingDriver;

class CachingSqlLoggerMiddleware implements Middleware
{

    private CachingSqlLogger $sqlLogger;

    public function __construct(CachingSqlLogger $sqlLogger)
    {
        $this->sqlLogger = $sqlLogger;
    }

    public function wrap(Driver $driver): Driver
    {
        return new LoggingDriver($driver, $this->sqlLogger);
    }

    /**
     * @return list<string>
     */
    public function getQueriesPerformed(): array
    {
        return $this->sqlLogger->getQueriesPerformed();
    }

    public function clean(): void
    {
        $this->sqlLogger->clean();
    }

}
