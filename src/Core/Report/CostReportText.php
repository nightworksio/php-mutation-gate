<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Cost\CostlyMutant;
use NightWorksIO\MutationGate\Core\Cost\CostlyTest;
use NightWorksIO\MutationGate\Core\Cost\CostReport;
use NightWorksIO\MutationGate\Core\Cost\Route;
use NightWorksIO\MutationGate\Core\Cost\RouteCost;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * Where a run's minutes went, as the step summary shows it, collapsed
 * (ADR-0230): the run's wall and runner time, jobs, rounds and unjudged
 * mutants, and its core time against one run of the test suite; each
 * route's mutants, wall time and core-seconds per mutant beside the fork
 * floor; the shards' time in no route; and the costliest mutants and tests.
 *
 * @internal
 */
final readonly class CostReportText
{
    private const string SUMMARY = "<details><summary>Where this run's minutes went</summary>";

    private const string RUN = '%s wall, %s runner time, %d %s in %d %s, %d unjudged.';

    private const string MULTIPLE = 'Core time %s: %.1f test suite runs of %s each. A full run should cost 2 to 3.';

    private const string NO_SUITE = 'Core time %s. The coverage map timed no test to set it against.';

    private const string FLOOR = 'A fork alone takes %.2fs of a core: no route can judge a mutant for less.';

    private const string OUTSIDE = "%s of the shards' time went to no route: start-up, coverage, trials, controls.";

    private const string ROUTE_ROW = '| %s | %d | %s | %s |';

    private const string MUTANT_ROW = '| %s | %s | %s | %s | %s |';

    private const string TEST_ROW = '| %s | %s | %d | %s |';

    /** The section of a verdict whose run was costed; nothing where it says nothing of where its minutes went. */
    public static function of(Verdict $verdict): string
    {
        $timings = $verdict->account()->timings();
        $report = $timings instanceof RunTimings ? $timings->report() : NotGiven::value();

        return $report instanceof CostReport ? self::markdown($report) : '';
    }

    public static function markdown(CostReport $report): string
    {
        $spent = $report->spent();
        $multiple = $report->multiple();
        $jobs = $report->jobs();
        $rounds = $report->rounds();

        return implode("\n\n", [
            self::SUMMARY,
            implode("\n", [
                sprintf(
                    self::RUN,
                    $spent->wall()->text(),
                    $spent->runner()->text(),
                    $jobs,
                    $jobs === 1 ? 'job' : 'jobs',
                    $rounds,
                    $rounds === 1 ? 'round' : 'rounds',
                    $report->unjudged(),
                ),
                $multiple instanceof NotGiven
                    ? sprintf(self::NO_SUITE, $report->coreSeconds()->text())
                    : sprintf(self::MULTIPLE, $report->coreSeconds()->text(), $multiple, $report->suite()->text()),
            ]),
            implode("\n", [
                '| Route | Mutants | Wall | Core-seconds a mutant |',
                '|---|---|---|---|',
                ...array_map(self::route(...), [...$report->routes()]),
            ]),
            implode("\n", [
                sprintf(self::FLOOR, CostReport::FORK_FLOOR),
                sprintf(self::OUTSIDE, $report->outside()->text()),
            ]),
            ...count($report->mutants()) === 0 ? [] : [implode("\n", [
                '| Costliest mutant | Where | Mutator | Route | Seconds |',
                '|---|---|---|---|---|',
                ...array_map(self::mutant(...), [...$report->mutants()]),
            ])],
            ...count($report->tests()) === 0 ? [] : [implode("\n", [
                '| Costliest test | Own time | Mutants on its lines | At most |',
                '|---|---|---|---|',
                ...array_map(self::test(...), [...$report->tests()]),
            ])],
            '</details>',
        ]);
    }

    private static function route(RouteCost $route): string
    {
        $each = $route->perMutant();

        return sprintf(
            self::ROUTE_ROW,
            $route->route()->value,
            $route->mutants(),
            $route->seconds()->text(),
            $each instanceof Seconds ? self::seconds($each) : '',
        );
    }

    private static function mutant(CostlyMutant $costly): string
    {
        $mutant = $costly->mutant();
        $location = $mutant->location();
        $route = $costly->route();

        return sprintf(
            self::MUTANT_ROW,
            Escape::code($mutant->id()->value()),
            Escape::code(sprintf('%s:%d', $location->file()->value(), $location->start()->number())),
            Escape::code($mutant->mutator()),
            $route instanceof Route ? $route->value : '',
            self::seconds($costly->seconds()),
        );
    }

    private static function test(CostlyTest $costly): string
    {
        return sprintf(
            self::TEST_ROW,
            Escape::code($costly->test()->value()),
            self::seconds($costly->own()),
            $costly->mutants(),
            $costly->atMost()->text(),
        );
    }

    /** Seconds to the hundredth, as a mutant's or a test's own time is read against the fork floor. */
    private static function seconds(Seconds $seconds): string
    {
        return sprintf('%.2fs', $seconds->seconds());
    }
}
