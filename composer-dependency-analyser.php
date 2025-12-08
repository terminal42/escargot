<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->ignoreErrorsOnPackage('doctrine/dbal', [ErrorType::DEV_DEPENDENCY_IN_PROD]) // Optional - only required for DoctrineQueue
;
