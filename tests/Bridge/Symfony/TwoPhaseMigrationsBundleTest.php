<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Bridge\Symfony;

use LogicException;
use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\Command\MigrationCheckCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationGenerateCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationInitCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationRunCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationSkipCommand;
use ShipMonk\Doctrine\Migration\MigrationConfig;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\MigrationServiceRegistry;
use Symfony\Component\Config\Definition\ConfigurationInterface;
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

class TwoPhaseMigrationsBundleTest extends TestCase
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
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Either "migrations_dir"');

        $this->loadBundleExtension([]);
    }

    public function testConfigurationConflictingMigrationsDirAndNamespaces(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot use both');

        $this->loadBundleExtension([
            'migrations_dir' => __DIR__ . '/../../..',
            'namespaces' => [
                'default' => [
                    'migrations_dir' => __DIR__ . '/../../..',
                ],
            ],
        ]);
    }

    public function testConfigurationMultipleNamespaces(): void
    {
        $config = $this->processConfiguration([
            'namespaces' => [
                'default' => [
                    'migrations_dir' => __DIR__ . '/../../..',
                ],
                'analytics' => [
                    'migrations_dir' => __DIR__ . '/../../..',
                    'entity_manager' => 'analytics',
                    'migration_table_name' => 'analytics_migration',
                ],
            ],
        ]);

        self::assertArrayHasKey('namespaces', $config);
        /** @var array<string, array<string, mixed>> $namespaces */
        $namespaces = $config['namespaces'];
        self::assertCount(2, $namespaces);
        self::assertArrayHasKey('default', $namespaces);
        self::assertArrayHasKey('analytics', $namespaces);
        self::assertSame('analytics', $namespaces['analytics']['entity_manager'] ?? null);
        self::assertSame('analytics_migration', $namespaces['analytics']['migration_table_name'] ?? null);
    }

    public function testServicesAreRegistered(): void
    {
        $migrationsDir = dirname(__DIR__, 2) . '/../tmp/bundle-test-migrations';

        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0777, true);
        }

        $bundle = new TwoPhaseMigrationsBundle();
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

        $bundle->loadExtension($config, $containerConfigurator, $containerBuilder);
        self::assertTrue($containerBuilder->hasDefinition(MigrationConfig::class . '.default'));
        self::assertTrue($containerBuilder->hasDefinition(MigrationService::class . '.default'));
        self::assertTrue($containerBuilder->hasAlias(MigrationConfig::class));
        self::assertTrue($containerBuilder->hasAlias(MigrationService::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationServiceRegistry::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationInitCommand::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationRunCommand::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationSkipCommand::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationCheckCommand::class));
        self::assertTrue($containerBuilder->hasDefinition(MigrationGenerateCommand::class));

        $migrationConfigDef = $containerBuilder->getDefinition(MigrationConfig::class . '.default');
        self::assertSame($migrationsDir, $migrationConfigDef->getArgument('$migrationsDir'));
        self::assertNull($migrationConfigDef->getArgument('$migrationTableName'));

        $migrationServiceDef = $containerBuilder->getDefinition(MigrationService::class . '.default');
        self::assertTrue($migrationServiceDef->isAutowired());

        $commandDef = $containerBuilder->getDefinition(MigrationInitCommand::class);
        self::assertTrue($commandDef->hasTag('console.command'));
    }

    public function testMultipleNamespacesServicesRegistered(): void
    {
        $migrationsDir = dirname(__DIR__, 2) . '/../tmp/bundle-test-migrations';

        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0777, true);
        }

        $bundle = new TwoPhaseMigrationsBundle();
        $containerBuilder = new ContainerBuilder(new ParameterBag());

        $config = $this->processConfiguration([
            'namespaces' => [
                'default' => [
                    'migrations_dir' => $migrationsDir,
                ],
                'analytics' => [
                    'migrations_dir' => $migrationsDir,
                    'entity_manager' => 'analytics',
                ],
            ],
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

        $bundle->loadExtension($config, $containerConfigurator, $containerBuilder);
        self::assertTrue($containerBuilder->hasDefinition(MigrationConfig::class . '.default'));
        self::assertTrue($containerBuilder->hasDefinition(MigrationConfig::class . '.analytics'));
        self::assertTrue($containerBuilder->hasDefinition(MigrationService::class . '.default'));
        self::assertTrue($containerBuilder->hasDefinition(MigrationService::class . '.analytics'));
        self::assertTrue($containerBuilder->hasDefinition(MigrationServiceRegistry::class));

        // No aliases when multiple namespaces
        self::assertFalse($containerBuilder->hasAlias(MigrationConfig::class));
        self::assertFalse($containerBuilder->hasAlias(MigrationService::class));

        // Analytics service should have entity manager reference
        $analyticsDef = $containerBuilder->getDefinition(MigrationService::class . '.analytics');
        self::assertTrue($analyticsDef->isAutowired());
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function processConfiguration(array $config): array
    {
        $bundle = new TwoPhaseMigrationsBundle();
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

    /**
     * @param array<string, mixed> $rawConfig
     */
    private function loadBundleExtension(array $rawConfig): void
    {
        $bundle = new TwoPhaseMigrationsBundle();
        $containerBuilder = new ContainerBuilder(new ParameterBag());
        $config = $this->processConfiguration($rawConfig);

        $instanceof = [];
        $containerConfigurator = new ContainerConfigurator(
            $containerBuilder,
            new PhpFileLoader($containerBuilder, new FileLocator(__DIR__)),
            $instanceof,
            __DIR__,
            __FILE__,
            'test',
        );

        $bundle->loadExtension($config, $containerConfigurator, $containerBuilder);
    }

}
