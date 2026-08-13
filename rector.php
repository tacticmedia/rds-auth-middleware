<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withParallel()
    ->withPHPStanConfigs([__DIR__.'/phpstan.dist.neon'])
    ->withPreparedSets(codeQuality: true, codingStyle: true)
    ->withAttributesSets(symfony: true, doctrine: true, gedmo: true, sensiolabs: true)
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    ->withComposerBased(
        doctrine: true,
        phpunit: true,
    )
;
