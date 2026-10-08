<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use function is_file;
use function is_string;
use function sprintf;

/**
 * A mutant's own process's arguments, rewritten to run its tests in the order
 * the plugin wrote for it: the likely killers first, the rest fastest first.
 * Where no order was written, or the process is no mutant's, they are as
 * they were, so the tests run in Pest's own order.
 *
 * PHPUnit warns, and so exits with a failure a mutant would count as a kill,
 * when an option is given twice, when two options contradict, and when it is
 * asked to order by defects with its test run history not recorded. So the
 * rewrite drops every cache directory, order, recording and caching option
 * the arguments hold, Pest's own among them, before it adds its own.
 */
final readonly class Reordering
{
    /**
     * @param  list<string> $arguments
     * @return list<string>
     */
    public static function of(array $arguments, string|false $directory, string|false $mutated): array
    {
        if (! is_string($directory) || $directory === '' || ! is_string($mutated) || $mutated === '') {
            return $arguments;
        }

        $seed = Seed::directoryOf($directory, $mutated);

        return is_file(sprintf('%s/%s', $seed, Seed::HISTORY)) ? [
            ...self::without($arguments),
            sprintf('%s=%s', Option::CacheDirectory->value, $seed),
            Option::Record->value,
            sprintf('%s=defects,duration-ascending', Option::OrderBy->value),
        ] : $arguments;
    }

    /**
     * @param  list<string> $arguments
     * @return list<string>
     */
    private static function without(array $arguments): array
    {
        $kept = [];
        $valueNext = false;

        foreach ($arguments as $argument) {
            $option = Option::in($argument);
            $kept = $valueNext || $option instanceof Option ? $kept : [...$kept, $argument];
            $valueNext = $option instanceof Option && $option->takesAValue() && $argument === $option->value;
        }

        return $kept;
    }
}
