<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Bridge\Symfony;

use LogicException;
use ShipMonk\Doctrine\Migration\Command\MigrationCheckCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationGenerateCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationInitCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationRunCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationSkipCommand;
use ShipMonk\Doctrine\Migration\MigrationConfig;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\MigrationServiceRegistry;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function array_key_first;
use function count;
use function dirname;

class TwoPhaseMigrationsBundle extends AbstractBundle
{

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('migrations_dir')->defaultNull()->end()
            ->scalarNode('migration_table_name')->defaultNull()->end()
            ->scalarNode('migration_class_namespace')->defaultNull()->end()
            ->scalarNode('migration_class_prefix')->defaultNull()->end()
            ->arrayNode('excluded_tables')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->scalarNode('template_file_path')->defaultNull()->end()
            ->scalarNode('template_indent')->defaultNull()->end()
            ->arrayNode('namespaces')
            ->useAttributeAsKey('name')
            ->arrayPrototype()
            ->children()
            ->scalarNode('migrations_dir')->isRequired()->end()
            ->scalarNode('migration_table_name')->defaultNull()->end()
            ->scalarNode('migration_class_namespace')->defaultNull()->end()
            ->scalarNode('migration_class_prefix')->defaultNull()->end()
            ->arrayNode('excluded_tables')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->scalarNode('template_file_path')->defaultNull()->end()
            ->scalarNode('template_indent')->defaultNull()->end()
            ->scalarNode('entity_manager')->defaultNull()->end()
            ->end()
            ->end()
            ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension( // @phpstan-ignore method.childParameterType, method.childParameterType
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void
    {
        $services = $container->services();

        $namespaces = $this->resolveNamespaces($config);

        $registryArgs = [];

        /** @var array{migrations_dir: string, migration_table_name: ?string, migration_class_namespace: ?string, migration_class_prefix: ?string, excluded_tables: list<string>, template_file_path: ?string, template_indent: ?string, entity_manager: ?string} $nsConfig */
        foreach ($namespaces as $name => $nsConfig) {
            $configServiceId = MigrationConfig::class . '.' . $name;
            $migrationServiceId = MigrationService::class . '.' . $name;

            $services->set($configServiceId, MigrationConfig::class)
                ->args([
                    '$migrationsDir' => $nsConfig['migrations_dir'],
                    '$migrationTableName' => $nsConfig['migration_table_name'],
                    '$migrationClassNamespace' => $nsConfig['migration_class_namespace'],
                    '$migrationClassPrefix' => $nsConfig['migration_class_prefix'],
                    '$excludedTables' => $nsConfig['excluded_tables'],
                    '$templateFilePath' => $nsConfig['template_file_path'],
                    '$templateIndent' => $nsConfig['template_indent'],
                ]);

            $migrationServiceDef = $services->set($migrationServiceId, MigrationService::class)
                ->autowire()
                ->arg('$config', new Reference($configServiceId));

            if ($nsConfig['entity_manager'] !== null) {
                $migrationServiceDef->arg('$entityManager', new Reference('doctrine.orm.' . $nsConfig['entity_manager'] . '_entity_manager'));
            }

            $registryArgs[$name] = new Reference($migrationServiceId);
        }

        // Register default aliases for single-namespace backward compat
        if (count($namespaces) === 1) {
            $onlyName = array_key_first($namespaces);
            $services->alias(MigrationConfig::class, MigrationConfig::class . '.' . $onlyName);
            $services->alias(MigrationService::class, MigrationService::class . '.' . $onlyName);
        }

        $services->set(MigrationServiceRegistry::class)
            ->args([$registryArgs]);

        $services->set(MigrationInitCommand::class)
            ->autowire()
            ->tag('console.command');

        $services->set(MigrationRunCommand::class)
            ->autowire()
            ->tag('console.command');

        $services->set(MigrationSkipCommand::class)
            ->autowire()
            ->tag('console.command');

        $services->set(MigrationCheckCommand::class)
            ->autowire()
            ->tag('console.command');

        $services->set(MigrationGenerateCommand::class)
            ->autowire()
            ->tag('console.command');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array<string, mixed>>
     */
    private function resolveNamespaces(array $config): array
    {
        $hasNamespaces = isset($config['namespaces']) && $config['namespaces'] !== [];
        $hasMigrationsDir = ($config['migrations_dir'] ?? null) !== null;

        if ($hasNamespaces && $hasMigrationsDir) {
            throw new LogicException('Cannot use both "migrations_dir" and "namespaces" at the same time. Use "namespaces" for multiple namespaces.');
        }

        if (!$hasNamespaces && !$hasMigrationsDir) {
            throw new LogicException('Either "migrations_dir" (for single namespace) or "namespaces" (for multiple namespaces) must be configured.');
        }

        // Multi-namespace config
        if ($hasNamespaces) {
            return $config['namespaces']; // @phpstan-ignore return.type
        }

        // Single namespace (backward compat)
        return [
            'default' => [
                'migrations_dir' => $config['migrations_dir'],
                'migration_table_name' => $config['migration_table_name'] ?? null,
                'migration_class_namespace' => $config['migration_class_namespace'] ?? null,
                'migration_class_prefix' => $config['migration_class_prefix'] ?? null,
                'excluded_tables' => $config['excluded_tables'] ?? [],
                'template_file_path' => $config['template_file_path'] ?? null,
                'template_indent' => $config['template_indent'] ?? null,
                'entity_manager' => null,
            ],
        ];
    }

    public function getPath(): string
    {
        return dirname(__DIR__, 2);
    }

}
