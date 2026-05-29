<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;
use function crc32;
use function sha1;

class MigrationLockTest extends TestCase
{

    public function testNoOpOnSqlite(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('executeQuery');

        $lock = new MigrationLock($connection, 'migration', 300);
        $lock->acquire();
        $lock->release();
    }

    public function testMysqlAcquireAndRelease(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('getDatabase')->willReturn('testdb');

        $expectedName = sha1('migration@testdb');

        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT GET_LOCK(?, ?)', [$expectedName, 5])
            ->willReturn(1);

        $connection->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT RELEASE_LOCK(?)', [$expectedName]);

        $lock = new MigrationLock($connection, 'migration', 5);
        $lock->acquire();
        $lock->release();
    }

    public function testMysqlAcquireTimeoutThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('getDatabase')->willReturn('testdb');
        $connection->method('fetchOne')->willReturn(0); // 0 = GET_LOCK timed out

        $lock = new MigrationLock($connection, 'migration', 5);

        $this->expectException(MigrationLockException::class);
        $this->expectExceptionMessage('within 5 seconds');

        try {
            $lock->acquire();
        } catch (MigrationLockException $e) {
            self::assertSame(5, $e->timeoutSeconds);

            throw $e;
        }
    }

    public function testPostgresAcquireAndRelease(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $expectedKey = crc32('migration');

        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT pg_try_advisory_lock(?)', [$expectedKey])
            ->willReturn(true);

        $connection->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT pg_advisory_unlock(?)', [$expectedKey]);

        $lock = new MigrationLock($connection, 'migration', 5);
        $lock->acquire();
        $lock->release();
    }

    public function testPostgresAcquireTimeoutThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn(false); // lock not free

        $lock = new MigrationLock($connection, 'migration', 0); // 0s timeout = try once then fail without sleeping

        $this->expectException(MigrationLockException::class);
        $lock->acquire();
    }

}
