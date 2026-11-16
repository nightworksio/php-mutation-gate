<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_keys;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The tests of the coverage map a patched Pest loaded, read in its parent
 * process, as php-code-coverage writes one since version 14, the one the
 * gate reads: every test the map ran, and how long each took. Each mutant's
 * filter names a class or a data set whole by them (see Selection), and its
 * limit is sized from them (see MutantTime).
 *
 * @internal
 */
final class LoadedMap
{
    /** @var array<string, float> each test's seconds, by its id, as the map timed it */
    private static array $seconds = [];

    /** @var list<Suite> the map's tests, counted by class and data set, once read */
    private static array $suite = [];

    /** @var list<TestTimes> the map's tests' own times, once read */
    private static array $times = [];

    /**
     * Keeps every test of the coverage map Pest loaded, and its seconds.
     *
     * @param array{testResults?: array<string, array{time: float}>} $loaded
     */
    public static function remember(array $loaded): void
    {
        $results = array_key_exists('testResults', $loaded) ? $loaded['testResults'] : [];
        self::$seconds = [];
        self::$suite = [];
        self::$times = [];

        foreach ($results as $test => $result) {
            self::$seconds[$test] = $result['time'];
        }
    }

    /** @return list<string> every test the map ran, by its id */
    public static function tests(): array
    {
        return array_keys(self::$seconds);
    }

    /** @return array<string, float> each test's own seconds, by its id, of every test the map timed at any */
    public static function timed(): array
    {
        return array_filter(self::$seconds, static fn(float $seconds): bool => $seconds > 0.0);
    }

    /** The own time of each test the map timed, read once. */
    public static function times(): TestTimes
    {
        if (self::$times === []) {
            $times = [];

            foreach (self::$seconds as $test => $seconds) {
                $times[] = TestTime::of(TestId::of($test), Seconds::of($seconds));
            }

            self::$times = [TestTimes::of(...$times)];
        }

        return self::$times[0];
    }

    /** The map's tests, counted by class and data set, read once. */
    public static function suite(): Suite
    {
        if (self::$suite === []) {
            self::$suite = [Suite::named(self::tests())];
        }

        return self::$suite[0];
    }

    /** How long a test took, as the map timed it; none where it did not time it, or timed it at no seconds. */
    public static function secondsOf(string $test): float|NotGiven
    {
        return array_key_exists($test, self::$seconds) && self::$seconds[$test] > 0.0
            ? self::$seconds[$test]
            : NotGiven::value();
    }
}
