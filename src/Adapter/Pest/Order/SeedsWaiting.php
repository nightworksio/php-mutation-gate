<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use function array_filter;
use function array_flip;
use function array_key_exists;
use function array_map;
use function array_values;

use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;

/**
 * The seeding of a run whose streams judge its mutants first (ADR-0117),
 * held until the streams are done: only a mutant they leave for its own run
 * reads its seed, so only those are written, as Pest takes its mutants in
 * order to run them (see Verdicts).
 *
 * @internal
 */
final class SeedsWaiting
{
    /** @var list<array{Seeder, MutationSuite}> the seeding held, at most one */
    private static array $held = [];

    /** Holds this suite's seeding until its mutants are taken to run. */
    public static function hold(Seeder $seeder, MutationSuite $suite): void
    {
        self::$held = [[$seeder, $suite]];
    }

    /**
     * Writes the seeds of those of these mutants that still have no result,
     * the ones that run on their own; nothing where no seeding is held.
     *
     * @param list<MutationTest> $tests
     */
    public static function seedFor(array $tests): void
    {
        $left = array_flip(array_values(array_map(
            static fn(MutationTest $test): string => $test->mutation->modifiedSourcePath,
            array_filter($tests, static fn(MutationTest $test): bool => $test->result() === MutationTestResult::None),
        )));

        foreach (self::$held as [$seeder, $suite]) {
            $seeder->seeded($suite, static fn(string $copy): bool => array_key_exists($copy, $left));
        }

        self::$held = [];
    }
}
