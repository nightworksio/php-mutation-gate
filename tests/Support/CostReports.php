<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Cost\CostReport;
use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\Route;
use NightWorksIO\MutationGate\Core\Cost\RoutedMutant;
use NightWorksIO\MutationGate\Core\Cost\RunSpend;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\ShardSpend;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

use function sprintf;

/**
 * A run whose minutes the cost report accounts for: two shards, one of four
 * cores and one of two, with a second wave's job; five mutants judged, one
 * on each route, and one no route ran; and a suite of two timed tests.
 */
final readonly class CostReports
{
    /** A mutant of `src/Money.php` at this line, of this status, timed where it was. */
    public static function mutant(int $line, MutantStatus $status, Seconds|Unmeasured $seconds): Mutant
    {
        $diff = sprintf('line %d', $line);

        return Mutant::of(
            MutantId::hash(Path::of('src/Money.php'), 'LessThan', $diff, $line),
            sprintf('native-%d', $line),
            Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
            Mutation::of('LessThan', MutatorFamily::Boundary, $diff),
            $status,
            $seconds,
        );
    }

    /**
     * The first shard: 100 s on four cores, 10 of coverage and 80 of
     * mutation, 30 and 20 of which were the passes, then 5 of static check.
     * The second: 50 s on two cores, 30 of mutation and 15 of fresh runs,
     * with one job of a second wave.
     *
     * @return list<ShardSpend>
     */
    public static function shards(): array
    {
        $at = static fn(Step $step, float $since, float $took): StepTime => StepTime::of(
            $step,
            Seconds::of($since),
            Seconds::of($took),
        );

        return [
            ShardSpend::of(Seconds::of(100.0), StepTimes::of(
                $at(Step::Coverage, 0.0, 10.0),
                $at(Step::Mutation, 10.0, 80.0),
                $at(Step::FirstPass, 10.0, 30.0),
                $at(Step::SecondPass, 40.0, 20.0),
                $at(Step::StaticCheck, 90.0, 5.0),
            ), ProcessCount::of(4), 0),
            ShardSpend::of(Seconds::of(50.0), StepTimes::of(
                $at(Step::Mutation, 0.0, 30.0),
                $at(Step::FreshRuns, 30.0, 15.0),
            ), ProcessCount::of(2), 1),
        ];
    }

    /** @return list<RoutedMutant> */
    public static function judged(): array
    {
        return [
            RoutedMutant::of(self::mutant(7, MutantStatus::Killed, Seconds::of(0.5)), Route::StreamFirstPass),
            RoutedMutant::of(self::mutant(9, MutantStatus::Killed, Seconds::of(2.0)), Route::StreamSecondPass),
            RoutedMutant::of(self::mutant(9, MutantStatus::Survived, Unmeasured::duration()), Route::OwnRun),
            RoutedMutant::of(self::mutant(11, MutantStatus::KilledByStaticAnalysis, Seconds::of(1.0)), Route::StaticCheck),
            RoutedMutant::of(self::mutant(13, MutantStatus::Uncovered, Unmeasured::duration()), NotGiven::value()),
        ];
    }

    /**
     * `MoneyTest::fits` runs lines 7 and 9 in 0.25 s; `PriceTest::adds`
     * runs line 9 in 2 s; `CartTest::totals` runs line 11 and was not timed.
     */
    public static function coverage(): CoverageMap
    {
        $money = Path::of('src/Money.php');
        $fits = TestId::of('MoneyTest::fits');
        $adds = TestId::of('PriceTest::adds');

        return CoverageMap::of()
            ->covered($money, Line::of(7), $fits)
            ->covered($money, Line::of(9), $fits)
            ->covered($money, Line::of(9), $adds)
            ->covered($money, Line::of(11), TestId::of('CartTest::totals'))
            ->timed($fits, Seconds::of(0.25))
            ->timed($adds, Seconds::of(2.0));
    }

    /** A run of 200 s wall and 400 s runner time, its plan timed. */
    public static function timings(): RunTimings
    {
        return RunTimings::of('github:1/1', RunTime::measured(Seconds::of(200.0), Seconds::of(400.0)))
            ->withPlan(Phase::of(Moment::at('2026-11-19T10:00:00Z'), Seconds::of(30.0)));
    }

    /** The report of this run, its verdict's job on two cores, naming its costliest two of each. */
    public static function sample(): CostReport
    {
        return RunSpend::report(
            self::shards(),
            self::judged(),
            self::timings(),
            JudgedMutants::of(
                JudgedMutant::of(self::mutant(7, MutantStatus::Killed, Seconds::of(0.5)), MutantJudgement::Killed),
                JudgedMutant::of(self::mutant(15, MutantStatus::Unjudged, Unmeasured::duration()), MutantJudgement::Unjudged),
            ),
            self::coverage(),
            ProcessCount::of(2),
            2,
        );
    }
}
