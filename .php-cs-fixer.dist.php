<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRules([
        '@auto' => true,
        '@PhpCsFixer' => true,
        '@Symfony' => true,
    ])
    ->setParallelConfig(\PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setFinder(
        (new Finder())
            ->in(__DIR__)
            ->exclude('var')
            ->exclude('vendor')
    )
;
