<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Bridge\Symfony;

use ShipMonk\Doctrine\Migration\Command\MigrationCheckCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationGenerateCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationInitCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationRunCommand;
use ShipMonk\Doctrine\Migration\Command\MigrationSkipCommand;
use ShipMonk\Doctrine\Migration\MigrationConfig;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function dirname;

class ShipMonkTwoPhaseMigrationsBundle extends AbstractBundle
{

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
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
            ->end();
    }

    /**
     * @param array{
     *     migrations_dir: string,
     *     migration_table_name: ?string,
     *     migration_class_namespace: ?string,
     *     migration_class_prefix: ?string,
     *     excluded_tables: list<string>,
     *     template_file_path: ?string,
     *     template_indent: ?string,
     * } $config
     */
    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void
    {
        $services = $container->services();

        $services->set(MigrationConfig::class)
            ->args([
                '$migrationsDir' => $config['migrations_dir'],
                '$migrationTableName' => $config['migration_table_name'],
                '$migrationClassNamespace' => $config['migration_class_namespace'],
                '$migrationClassPrefix' => $config['migration_class_prefix'],
                '$excludedTables' => $config['excluded_tables'] !== [] ? $config['excluded_tables'] : null,
                '$templateFilePath' => $config['template_file_path'],
                '$templateIndent' => $config['template_indent'],
            ]);

        $services->set(MigrationService::class)
            ->autowire();

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

    public function getPath(): string
    {
        return dirname(__DIR__, 2);
    }

}
