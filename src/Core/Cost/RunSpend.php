<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_filter;
use function array_key_exists;
use function array_slice;
use function count;
use function max;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

use function sprintf;
use function usort;

/**
 * Where a run's minutes went, worked out from what its shards spent and the
 * mutants they judged (ADR-0230): each route's seconds from the shards'
 * steps and the cores of their machines, each route's mutants from the
 * route each came to its verdict by, the costliest mutants by their own
 * seconds, and the costliest tests by their own seconds times the mutants
 * on their lines.
 *
 * @internal
 */
final readonly class RunSpend
{
    /**
     * The cost report of a run whose shards spent this and judged these mutants,
     * with these timings, judged as these, over this coverage map, its
     * verdict's job on a machine with these cores, naming this many of its
     * costliest mutants and tests.
     *
     * @param list<ShardSpend>   $shards
     * @param list<RoutedMutant> $judged
     */
    public static function report(
        array $shards,
        array $judged,
        RunTimings $timings,
        JudgedMutants $verdict,
        CoverageMap $coverage,
        ProcessCount $cores,
        int $costliest,
    ): CostReport {
        $routes = self::routeCosts($shards, $judged);
        $inRoutes = 0.0;
        $shardSeconds = 0.0;
        $shardCoreSeconds = 0.0;
        $shares = 0;

        foreach ($routes as $route) {
            $inRoutes += $route->seconds()->seconds();
        }

        foreach ($shards as $shard) {
            $shardSeconds += $shard->seconds()->seconds();
            $shardCoreSeconds += $shard->seconds()->seconds() * $shard->cores()->count();
            $shares += $shard->shares();
        }

        $spent = $timings->spent();
        $rest = max(0.0, $spent->runner()->seconds() - $shardSeconds) * $cores->count();
        $unjudged = 0;

        foreach ($verdict as $mutant) {
            $unjudged += $mutant->judgement() === MutantJudgement::Unjudged ? 1 : 0;
        }

        return CostReport::of(
            RouteCosts::of(...$routes),
            Seconds::of(max(0.0, $shardSeconds - $inRoutes)),
            CostlyMutants::of(...array_slice(self::costliestMutants($judged), 0, $costliest)),
            CostlyTests::of(...array_slice(self::costliestTests($judged, $coverage), 0, $costliest)),
            $spent,
            RunShape::of(
                count($shards) + $shares + 1 + ($timings->plan() instanceof Phase ? 1 : 0),
                match (true) {
                    $shards === [] => 0,
                    $shares > 0 => 2,
                    default => 1,
                },
                $unjudged,
            ),
            Seconds::of($shardCoreSeconds + $rest),
            $coverage->durations()->total(),
        );
    }

    /**
     * @param  list<ShardSpend>   $shards
     * @param  list<RoutedMutant> $judged
     * @return list<RouteCost>
     */
    private static function routeCosts(array $shards, array $judged): array
    {
        $routes = [];

        foreach (Route::cases() as $route) {
            $seconds = 0.0;
            $coreSeconds = 0.0;

            foreach ($shards as $shard) {
                $in = $shard->in($route)->seconds();
                $seconds += $in;
                $coreSeconds += $in * $shard->cores()->count();
            }

            $mutants = count(array_filter($judged, static fn(RoutedMutant $one): bool => $one->route() === $route));
            $routes[] = RouteCost::of($route, $mutants, Seconds::of($seconds), Seconds::of($coreSeconds));
        }

        return $routes;
    }

    /**
     * @param  list<RoutedMutant> $judged
     * @return list<CostlyMutant>
     */
    private static function costliestMutants(array $judged): array
    {
        $timed = [];

        foreach ($judged as $one) {
            $duration = $one->mutant()->duration();
            if ($duration instanceof Seconds) {
                $timed[] = CostlyMutant::of($one->mutant(), $duration, $one->route());
            }
        }

        usort(
            $timed,
            static fn(CostlyMutant $one, CostlyMutant $other): int => [
                $other->seconds()->seconds(),
                $one->mutant()->id()->value(),
            ] <=> [$one->seconds()->seconds(), $other->mutant()->id()->value()],
        );

        return $timed;
    }

    /**
     * How many of these mutants sit on each line, by its line and file.
     *
     * @param  list<RoutedMutant>                      $judged
     * @return array<string, array{Location, int}>
     */
    private static function perLine(array $judged): array
    {
        $lines = [];

        foreach ($judged as $one) {
            $location = $one->mutant()->location();
            $at = sprintf('%d:%s', $location->start()->number(), $location->file()->value());
            $lines[$at] = [$location, array_key_exists($at, $lines) ? $lines[$at][1] + 1 : 1];
        }

        return $lines;
    }

    /**
     * How many of the mutants each test runs the line of, by its id.
     *
     * @param  array<string, array{Location, int}> $lines
     * @return array<array-key, int>
     */
    private static function onTheirLines(array $lines, CoverageMap $coverage): array
    {
        $counts = [];

        foreach ($lines as [$location, $count]) {
            $start = $location->start();

            foreach ($coverage->testsCovering($location->file(), $start, $start) as $test) {
                $id = $test->value();
                $counts[$id] = array_key_exists($id, $counts) ? $counts[$id] + $count : $count;
            }
        }

        return $counts;
    }

    /**
     * @param  list<RoutedMutant> $judged
     * @return list<CostlyTest>
     */
    private static function costliestTests(array $judged, CoverageMap $coverage): array
    {
        $counts = self::onTheirLines(self::perLine($judged), $coverage);
        $tests = [];

        foreach ($counts as $id => $count) {
            $test = TestId::of(sprintf('%s', $id));
            $own = $coverage->durations()->of($test);
            if ($own instanceof Seconds) {
                $tests[] = CostlyTest::of($test, $own, $count);
            }
        }

        usort(
            $tests,
            static fn(CostlyTest $one, CostlyTest $other): int => [
                $other->atMost()->seconds(),
                $one->test()->value(),
            ] <=> [$one->atMost()->seconds(), $other->test()->value()],
        );

        return $tests;
    }
}
