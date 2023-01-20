<?php declare(strict_types = 1);

namespace App;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;

$config = [];

if (InstalledVersions::satisfies(new VersionParser(), 'doctrine/dbal', '>=3.2')) {
    $config['parameters']['ignoreErrors'][] = '#Call to function method_exists\(\) with Doctrine\\\\DBAL\\\\Schema\\\\AbstractSchemaManager and \'createComparator\' will always evaluate to true.#';
}


return $config;
