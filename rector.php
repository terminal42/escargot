<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\MethodCall\WithCallbackIdenticalToStandaloneAssertsRector;

return static function (RectorConfig $rectorConfig): void {
    // PHPUnit callbacks must return bool, but this rule produces void callbacks
    $rectorConfig->skip([WithCallbackIdenticalToStandaloneAssertsRector::class]);
};
