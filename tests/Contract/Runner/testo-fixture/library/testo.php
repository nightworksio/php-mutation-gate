<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

// The tests are in tests/ and plugins/stock/tests/, so each library's test
// files have the same directories whatever runs them.
return new ApplicationConfig(
    src: ['src', 'beside'],
    suites: [
        new SuiteConfig(name: 'library', location: ['tests']),
        new SuiteConfig(name: 'stock', location: ['plugins/stock/tests']),
    ],
);
