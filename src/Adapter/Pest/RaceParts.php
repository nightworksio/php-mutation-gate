<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function array_values;
use function count;

use const INF;

use function min;

use NightWorksIO\MutationGate\Core\Coverage\TestDurations;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usort;

/**
 * The parts a fresh run's covering tests are raced in beside it (ADR-0203).
 *
 * @internal
 */
final readonly class RaceParts
{
    /**
     * These covering tests dealt to this many parts, fastest first by these
     * times, one an untimed test after every timed one; none where that is
     * under two parts.
     *
     * @return list<TestIds>
     */
    public static function dealt(TestIds $covering, TestDurations $times, int $share): array
    {
        $tests = [...$covering];
        $parts = min($share, count($tests));

        if ($parts < 2) {
            return [];
        }

        $secondsOf = static function (TestId $test) use ($times): float {
            $seconds = $times->of($test);

            return $seconds instanceof Seconds ? $seconds->seconds() : INF;
        };
        usort($tests, static fn(TestId $one, TestId $other): int => $secondsOf($one) <=> $secondsOf($other));
        $dealt = [];

        foreach ($tests as $at => $test) {
            $dealt[$at % $parts][] = $test;
        }

        return array_map(static fn(array $part): TestIds => TestIds::of(...$part), array_values($dealt));
    }

}
