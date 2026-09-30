<?php

declare(strict_types=1);

use Library\Shapes;
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

/**
 * Runs the line src/Shapes.php holds, and appends which test ran it, and
 * whether in a mutant's process, to the file LIBRARY_TRACE names.
 */
function seen(string $label): bool
{
    $trace = getenv('LIBRARY_TRACE');
    $where = getenv('PEST_MUTATION_TESTING') === false ? 'suite' : 'mutant';

    if ($trace !== false) {
        file_put_contents($trace, sprintf("%s %s\n", $where, $label), FILE_APPEND | LOCK_EX);
    }

    return new Shapes()->seen();
}
