<?php

declare(strict_types=1);

use Pest\Mutate\Repositories\ConfigurationRepository;
use Pest\Support\Container;

// The library's own mutation config, which the gate's command line overrides.
// In force, it would mutate covered lines only, of a class the library does
// not have, never src/Held.php, stop at the first escaped or uncovered mutant,
// run escaped mutants first, and fail every run below a score of 100.
Container::getInstance()->get(ConfigurationRepository::class)->globalConfiguration()
    ->coveredOnly()
    ->class('Library\Nowhere')
    ->ignore('src/Held.php')
    ->stopOnUntested()
    ->stopOnUncovered()
    ->retry()
    ->min(100);
