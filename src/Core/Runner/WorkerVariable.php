<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_map;

/**
 * A variable that makes a process a parallel worker of another run: paratest's
 * and pest-plugin-mutate's for their workers, and Laravel's for its parallel
 * tests. A runner's shell unsets each by its name, whether or not the
 * environment it reads holds it, since a process also inherits what `$_ENV`
 * holds.
 */
enum WorkerVariable: string
{
    case Paratest = 'PARATEST';
    case TestToken = 'TEST_TOKEN';
    case UniqueTestToken = 'UNIQUE_TEST_TOKEN';
    case LaravelParallelTesting = 'LARAVEL_PARALLEL_TESTING';

    /** @return list<string> every one, by its name */
    public static function names(): array
    {
        return array_map(static fn(self $variable): string => $variable->value, self::cases());
    }
}
