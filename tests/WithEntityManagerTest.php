<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use function gc_collect_cycles;
use function is_file;
use function unlink;

trait WithEntityManagerTest
{

    /**
     * @return array{EntityManagerInterface, CachingSqlLogger}
     */
    public function createEntityManagerAndLogger(): array
    {
        $tmpDir = __DIR__ . '/../tmp';

        $databaseFile = $tmpDir . '/db.sqlite';

        if (is_file($databaseFile)) {
            gc_collect_cycles();
            unlink($databaseFile);
        }

        $logger = new CachingSqlLogger();

        $config = new Configuration();
        $config->setProxyNamespace('Tmp\Doctrine\Tests\Proxies');
        $config->setProxyDir($tmpDir . '/doctrine');
        $config->setAutoGenerateProxyClasses(false);
        $config->setSecondLevelCacheEnabled(false);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__]));
        $config->setMiddlewares([new CachingSqlLoggerMiddleware($logger)]);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $databaseFile,
        ], $config);

        $entityManager = new EntityManager($connection, $config);

        return [$entityManager, $logger];
    }

}
