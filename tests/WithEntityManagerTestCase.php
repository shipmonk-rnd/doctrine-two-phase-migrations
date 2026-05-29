<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use function getenv;
use function is_string;

/**
 * @phpstan-import-type Params from DriverManager
 */
trait WithEntityManagerTestCase
{

    /**
     * @return array{EntityManagerInterface, CachingSqlLogger}
     */
    public function createEntityManagerAndLogger(): array
    {
        $tmpDir = __DIR__ . '/../tmp';

        $logger = new CachingSqlLogger();

        $config = new Configuration();
        $config->setProxyNamespace('Tmp\Doctrine\Tests\Proxies');
        $config->setProxyDir($tmpDir . '/doctrine');
        $config->setAutoGenerateProxyClasses(false);
        $config->setSecondLevelCacheEnabled(false);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__]));
        $config->setMiddlewares([new CachingSqlLoggerMiddleware($logger)]);

        $connection = DriverManager::getConnection(self::getConnectionParams($tmpDir), $config);

        self::dropAllTables($connection);

        $entityManager = new EntityManager($connection, $config);

        return [$entityManager, $logger];
    }

    /**
     * Connection params are read from the DATABASE_URL environment variable (Doctrine DSN format),
     * falling back to a local SQLite file when it is not set. This lets the same test suite run
     * against SQLite, MySQL and PostgreSQL just by changing the environment.
     *
     * @return Params
     */
    private static function getConnectionParams(string $tmpDir): array
    {
        $databaseUrl = getenv('DATABASE_URL');

        if (is_string($databaseUrl) && $databaseUrl !== '') {
            $dsnParser = new DsnParser([
                'sqlite' => 'pdo_sqlite',
                'mysql' => 'pdo_mysql',
                'mariadb' => 'pdo_mysql',
                'postgres' => 'pdo_pgsql',
                'postgresql' => 'pdo_pgsql',
                'pgsql' => 'pdo_pgsql',
            ]);

            return $dsnParser->parse($databaseUrl);
        }

        return [
            'driver' => 'pdo_sqlite',
            'path' => $tmpDir . '/db.sqlite',
        ];
    }

    /**
     * Ensures every test starts with an empty schema regardless of the platform.
     */
    private static function dropAllTables(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();

        foreach ($schemaManager->listTableNames() as $tableName) {
            $schemaManager->dropTable($tableName);
        }
    }

}
