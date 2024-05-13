<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use function gc_collect_cycles;
use function is_file;
use function unlink;

trait WithEntityManagerTest
{

    public function createEntityManager(): EntityManagerInterface
    {
        $tmpDir = __DIR__ . '/../tmp';

        $databaseFile = $tmpDir . '/db.sqlite';

        if (is_file($databaseFile)) {
            gc_collect_cycles();
            unlink($databaseFile);
        }

        $config = new Configuration();
        $config->setProxyNamespace('Tmp\Doctrine\Tests\Proxies');
        $config->setProxyDir($tmpDir . '/doctrine');
        $config->setAutoGenerateProxyClasses(false);
        $config->setSecondLevelCacheEnabled(false);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver([__DIR__], false));

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $databaseFile,
        ]);
        return EntityManager::create($connection, $config, $connection->getEventManager());
    }

}
