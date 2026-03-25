<?php declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$config = new Configuration();

return $config
    ->ignoreErrorsOnPackageAndPath('symfony/config', __DIR__ . '/src/Bridge/Symfony', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPath('symfony/dependency-injection', __DIR__ . '/src/Bridge/Symfony', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPath('symfony/http-kernel', __DIR__ . '/src/Bridge/Symfony', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
