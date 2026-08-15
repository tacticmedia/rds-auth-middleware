<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;

return RectorConfig::configure()
    ->withParallel()
    ->withPHPStanConfigs([__DIR__.'/phpstan.dist.neon'])
    ->withPreparedSets(codeQuality: true, codingStyle: true)
    ->withAttributesSets(symfony: true, doctrine: true, gedmo: true, sensiolabs: true)
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // PHPUnit >=13.2 method renames do not exist on the phpunit ^12.5 floor.
        RenameMethodRector::class => [__DIR__.'/tests'],
    ])
    ->withPhpSets()
    ->withComposerBased(
        doctrine: true,
        phpunit: true,
    )
;
