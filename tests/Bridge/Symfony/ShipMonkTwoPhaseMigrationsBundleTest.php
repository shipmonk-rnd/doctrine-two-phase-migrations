<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Bridge\Symfony;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\Command\MigrationCheckCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationGenerateCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationInitCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationRunCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationSkipCommand;
use ShipMonk\Doctrine\Migration\MigrationConfig;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use function dirname;
use function is_dir;
use function mkdir;

class ShipMonkTwoPhaseMigrationsBundleTest extends TestCase
{

    public function testConfigurationDefaults(): void
    {
        $config = $this->processConfiguration([
            'migrations_dir' => __DIR__ . '/../../..',
        ]);

        self::assertSame(__DIR__ . '/../../..', $config['migrations_dir'] ?? null);
        self::assertNull($config['migration_table_name'] ?? null);
        self::assertNull($config['migration_class_namespace'] ?? null);
        self::assertNull($config['migration_class_prefix'] ?? null);
        self::assertSame([], $config['excluded_tables'] ?? null);
        self::assertNull($config['template_file_path'] ?? null);
        self::assertNull($config['template_indent'] ?? null);
    }

    public function testConfigurationFull(): void
    {
        $config = $this->processConfiguration([
            'migrations_dir' => __DIR__ . '/../../..',
            'migration_table_name' => 'custom_table',
            'migration_class_namespace' => 'App\\Migrations',
            'migration_class_prefix' => 'Version',
            'excluded_tables' => ['tmp_table'],
            'template_file_path' => __DIR__ . '/../../../src/template/migration.txt',
            'template_indent' => "\t\t",
        ]);

        self::assertSame('custom_table', $config['migration_table_name'] ?? null);
        self::assertSame('App\\Migrations', $config['migration_class_namespace'] ?? null);
        self::assertSame('Version', $config['migration_class_prefix'] ?? null);
        self::assertSame(['tmp_table'], $config['excluded_tables'] ?? null);
    }

    public function testConfigurationMissingRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('migrations_dir');

        $this->processConfiguration([]);
    }

    public function testServicesAreRegistered(): void
    {
        $migrationsDir = dirname(__DIR__, 2) . '/../tmp/bundle-test-migrations';

        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0777, true);
        }

        $bundle = new ShipMonkTwoPhaseMigrationsBundle();
        $containerBuilder = new ContainerBuilder(new ParameterBag());

        $config = $this->processConfiguration([
            'migrations_dir' => $migrationsDir,
        ]);

        $instanceof = [];
        $containerConfigurator = new ContainerConfigurator(
            $containerBuilder,
            new PhpFileLoader($containerBuilder, new FileLocator(__DIR__)),
            $instanceof,
            __DIR__,
            __FILE__,
            'test',
        );

        $bundle->loadExtension($config, $containerConfigurator, $containerBuilder); // @phpstan-ignore argument.type

        self::assertTrue($containerBuilder->hasDefinition(MigrationConfig::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationService::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationInitCommand::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationRunCommand::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationSkipCommand::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationCheckCommand::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationGenerateCommand::class));

        $migrationConfigDef = $containerBuilder->getDefinition(MigrationConfig::class);
        self::assertSame($migrationsDir, $migrationConfigDef->getArgument('$migrationsDir'));
        self::assertNull($migrationConfigDef->getArgument('$migrationTableName'));

        $migrationServiceDef = $containerBuilder->getDefinition(MigrationService::class);
        self::assertTrue($migrationServiceDef->isAutowired());

        $commandDef = $containerBuilder->getDefinition(MigrationInitCommand::class);
        self::assertTrue($commandDef->hasTag('console.command'));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function processConfiguration(array $config): array
    {
        $bundle = new ShipMonkTwoPhaseMigrationsBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);

        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        $processor = new Processor();
        /** @var array<string, mixed> $result */
        $result = $processor->processConfiguration($configuration, [$extension->getAlias() => $config]);
        return $result;
    }

}
