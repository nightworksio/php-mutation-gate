<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_any;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use Pest\Mutate\MutationTest;

use function strval;

/**
 * The mutants of a run's split streams whose verdict the first pass settled
 * (ADR-0194): each the second pass streams no site of and runs no test file
 * of any covering test of, so nothing the second finds of a test or of a
 * mutant can move it, and it is given before the second starts.
 *
 * @internal
 */
final readonly class SettledFirst
{
    /**
     * Those of these mutants, by Pest's id, the first pass settled, before a
     * second that streams these sites, forking each at these covering tests.
     *
     * @param  array<string, MutationTest>                                      $byId
     * @param  array<string, list<string>>                                      $covering the covering tests the shard
     *                                                                                    judges each mutant by
     * @param  array<string, array{string, int, string, array<string, string>}> $again    the sites the second streams
     * @param  array<string, list<string>>                                      $left     the covering tests it forks
     *                                                                                    each at
     * @return array<string, MutationTest>
     */
    public static function of(array $byId, array $covering, array $again, array $left): array
    {
        $forked = array_map(
            static fn(string $mutant): array => $left[$mutant],
            array_map(strval(...), array_keys($again)),
        );
        $streamed = self::filesOf(array_merge([], ...$forked));
        $settled = [];

        foreach ($byId as $mutant => $test) {
            $mutant = strval($mutant);
            $runs = array_any(
                array_keys(CoveringFiles::streamed($covering[$mutant])),
                static fn(string $file): bool => array_key_exists($file, $streamed),
            );

            if (! array_key_exists($mutant, $again) && ! $runs) {
                $settled[$mutant] = $test;
            }
        }

        return $settled;
    }

    /**
     * The test files a pass of streams forking at these tests runs: each
     * one's file and every file it needs, as its streams load them.
     *
     * @param  list<string>        $tests
     * @return array<string, true>
     */
    private static function filesOf(array $tests): array
    {
        $files = [];

        foreach (CoveringFiles::streamed($tests) as $unit) {
            foreach ($unit['files'] as $file) {
                $files[$file] = true;
            }
        }

        return $files;
    }
}
