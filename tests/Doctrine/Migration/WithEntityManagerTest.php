<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use function sys_get_temp_dir;

trait WithEntityManagerTest
{

    public function getEntityManager(): EntityManagerInterface
    {
        $config = new Configuration();
        $config->setProxyNamespace('Tmp\Doctrine\Tests\Proxies');
        $config->setProxyDir(sys_get_temp_dir() . '/doctrine');
        $config->setAutoGenerateProxyClasses(false);
        $config->setSecondLevelCacheEnabled(false);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver([__DIR__], false));

        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:'], $config);
        return EntityManager::create($connection, $config, $connection->getEventManager());
    }

}
