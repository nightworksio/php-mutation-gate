<?php

declare(strict_types=1);

use Tally\Configuration;

return Configuration::create()
    ->withPaths([__DIR__ . '/tests/Bench'])
    ->withIniEntries(['memory_limit=-1']);
