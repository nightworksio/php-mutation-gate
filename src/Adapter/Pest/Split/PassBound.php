<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function max;

/**
 * The wall seconds a pass would take were each mutant forked at every
 * covering test the shard judges it by (ADR-0196).
 *
 * @internal
 */
final readonly class PassBound
{
    /**
     * These mutants' covering tests' seconds together, by these times, over
     * this many processes: a test timed nowhere adds none, and fewer than
     * one process counts as one.
     *
     * @param array<array-key, list<string>> $covering each mutant's covering tests
     * @param array<string, float>           $times    the seconds each test took
     */
    public static function of(array $covering, array $times, int $processes): float
    {
        $seconds = 0.0;

        foreach ($covering as $tests) {
            foreach ($tests as $test) {
                if (array_key_exists($test, $times)) {
                    $seconds += $times[$test];
                }
            }
        }

        return $seconds / max(1, $processes);
    }
}
