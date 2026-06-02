<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use function crc32;
use function microtime;
use function min;
use function sha1;
use function sprintf;
use function usleep;

/**
 * Database-level lock ensuring only a single migration run executes at a time, even across processes.
 *
 * - MySQL / MariaDB: session-level named lock via GET_LOCK() / RELEASE_LOCK()
 * - PostgreSQL: session-level advisory lock via pg_try_advisory_lock() / pg_advisory_unlock()
 * - other platforms (including SQLite): no-op (SQLite serializes writers at the filesystem level)
 *
 * The lock is bound to the connection session, so it is released automatically when the connection
 * is closed - e.g. when a killed process drops its connection - preventing permanent dead-locks.
 */
class MigrationLock
{

    private const POSTGRES_POLL_INTERVAL_MICROSECONDS = 500_000; // 0.5s

    public function __construct(
        private readonly Connection $connection,
        private readonly string $lockName,
        private readonly int $timeoutSeconds,
    )
    {
    }

    public function acquire(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->acquireMysql();
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $this->acquirePostgres();
        }
        // other platforms (incl. SQLite): no-op
    }

    public function release(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(?)', [$this->getMysqlLockName()]);
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $this->connection->executeQuery('SELECT pg_advisory_unlock(?)', [$this->getPostgresLockKey()]);
        }
        // other platforms (incl. SQLite): no-op
    }

    private function acquireMysql(): void
    {
        // GET_LOCK blocks server-side up to the given timeout; returns 1 on success, 0 on timeout, NULL on error
        $result = $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [$this->getMysqlLockName(), $this->timeoutSeconds]);

        if (!$this->isTruthy($result)) {
            throw $this->createTimeoutException();
        }
    }

    private function acquirePostgres(): void
    {
        // there is no built-in wait timeout for advisory locks, so poll pg_try_advisory_lock until a deadline
        $key = $this->getPostgresLockKey();
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            if ($this->isTruthy($this->connection->fetchOne('SELECT pg_try_advisory_lock(?)', [$key]))) {
                return;
            }

            $remainingMicroseconds = (int) (($deadline - microtime(true)) * 1_000_000);

            if ($remainingMicroseconds <= 0) {
                throw $this->createTimeoutException();
            }

            // clamp the sleep to the remaining time so the wait does not overshoot the configured timeout
            usleep(min(self::POSTGRES_POLL_INTERVAL_MICROSECONDS, $remainingMicroseconds));
        }
    }

    private function getMysqlLockName(): string
    {
        // GET_LOCK names are server-wide, so scope them by database; SHA1 keeps the name within the 64-char limit
        return sha1($this->lockName . '@' . ($this->connection->getDatabase() ?? ''));
    }

    private function getPostgresLockKey(): int
    {
        // advisory locks are already scoped per database; crc32 yields a stable integer usable as the bigint key
        return crc32($this->lockName);
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function createTimeoutException(): MigrationLockException
    {
        return new MigrationLockException($this->timeoutSeconds, sprintf(
            'Could not acquire migration lock within %d seconds, another migration run is probably in progress.',
            $this->timeoutSeconds,
        ));
    }

}
