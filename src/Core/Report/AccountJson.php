<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;

use NightWorksIO\MutationGate\Core\Cost\Cost;
use NightWorksIO\MutationGate\Core\Cost\CostlyMutant;
use NightWorksIO\MutationGate\Core\Cost\CostlyTest;
use NightWorksIO\MutationGate\Core\Cost\CostReport;
use NightWorksIO\MutationGate\Core\Cost\Money;
use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\Rate;
use NightWorksIO\MutationGate\Core\Cost\Route;
use NightWorksIO\MutationGate\Core\Cost\RouteCost;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Cost\Unpriced;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\StepsRecord;
use NightWorksIO\MutationGate\Core\Pruning\PruningAccount;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use stdClass;

/**
 * The JSON report's `run`, `cost`, `savings` and `pruning`: the run's
 * timings, what it cost, and what it saved, each left out where the flows gave
 * the verdict no timings (ADR-0016, and ADR-0017); and what pruning left out,
 * left out where it left nothing out (ADR-0025); and the `heatmap`, left out
 * where the run scored no file (ADR-0100); and the `costReport`, where the
 * run's minutes went, left out where the run was not costed (ADR-0230).
 *
 * @internal
 *
 * @phpstan-import-type FileEntry from HeatmapJson
 *
 * @phpstan-type Timed array{start: string, seconds: float}
 * @phpstan-type Shard array{
 *     shard: int,
 *     start: string,
 *     seconds: float,
 *     steps: list<array{step: string, since: float, seconds: float, count?: int}>,
 * }
 * @phpstan-type Run array{
 *     id: string,
 *     traceId: string,
 *     phases: array{plan?: Timed, verdict?: Timed}|stdClass,
 *     shards: list<Shard>,
 *     units: array<string, int>,
 *     wallSeconds: float,
 *     runnerSeconds: float,
 *     measured: bool,
 * }
 * @phpstan-type Priced array{amount: float, currency: string}
 * @phpstan-type Figure array{wallSeconds: float, runnerSeconds: float, price?: Priced}
 * @phpstan-type CostEntry array{
 *     planned: Figure,
 *     measured: Figure,
 *     spared: array{seconds: float, price?: Priced},
 *     setupEstimated: bool,
 *     perRunnerMinute?: Priced,
 * }
 * @phpstan-type Saved array{
 *     fullRun: array{seconds: float, measuredPercent: int},
 *     saved: array{reachSeconds: float, proofsSeconds: float},
 *     sharding?: array{waitSavedSeconds: float, setupSeconds: float},
 * }
 * @phpstan-type RouteEntry array{
 *     route: string,
 *     mutants: int,
 *     seconds: float,
 *     coreSeconds: float,
 *     coreSecondsPerMutant?: float,
 * }
 * @phpstan-type CostlyMutantEntry array{
 *     id: string,
 *     file: string,
 *     line: int,
 *     mutator: string,
 *     seconds: float,
 *     route?: string,
 * }
 * @phpstan-type CostlyTestEntry array{test: string, ownSeconds: float, mutants: int, atMostSeconds: float}
 * @phpstan-type CostReportEntry array{
 *     forkFloorSeconds: float,
 *     routes: list<RouteEntry>,
 *     outsideRoutesSeconds: float,
 *     mutants: list<CostlyMutantEntry>,
 *     tests: list<CostlyTestEntry>,
 *     wallSeconds: float,
 *     runnerSeconds: float,
 *     jobs: int,
 *     rounds: int,
 *     unjudged: int,
 *     coreSeconds: float,
 *     suiteSeconds: float,
 *     suiteMultiple?: float,
 * }
 * @phpstan-type PrunedMutator array{name: string, window: int, lastSurvivor?: string}
 * @phpstan-type PruningEntry array{
 *     mutators: list<PrunedMutator>,
 *     units: int,
 *     carried: int,
 *     auditSeconds: float,
 *     savedSeconds: float,
 * }
 */
final readonly class AccountJson
{
    /**
     * @return array{
     *     run?: Run,
     *     cost?: CostEntry,
     *     savings?: Saved|array{noHistory: true},
     *     pruning?: PruningEntry,
     *     heatmap?: list<FileEntry>,
     *     costReport?: CostReportEntry,
     * }
     */
    public static function of(Verdict $verdict): array
    {
        $account = $verdict->account();
        $timings = $account->timings();
        $cost = $account->cost();
        $savings = $account->savings();
        $pruning = $account->pruning();

        return [
            ...$timings instanceof RunTimings ? ['run' => self::run($verdict, $timings)] : [],
            ...$cost instanceof Cost ? ['cost' => self::cost($cost)] : [],
            ...$timings instanceof RunTimings
                ? ['savings' => $savings instanceof Savings ? self::savings($savings) : ['noHistory' => true]]
                : [],
            ...$pruning->isNone() ? [] : ['pruning' => self::pruning($pruning)],
            ...HeatmapJson::report($account->heatmap()),
            ...$timings instanceof RunTimings ? self::reported($timings) : [],
        ];
    }

    /** @return array{costReport?: CostReportEntry} */
    private static function reported(RunTimings $timings): array
    {
        $report = $timings->report();

        return $report instanceof CostReport ? ['costReport' => self::report($report)] : [];
    }

    /** @return CostReportEntry */
    private static function report(CostReport $report): array
    {
        $multiple = $report->multiple();

        return [
            'forkFloorSeconds' => CostReport::FORK_FLOOR,
            'routes' => array_map(self::route(...), [...$report->routes()]),
            'outsideRoutesSeconds' => $report->outside()->seconds(),
            'mutants' => array_map(self::costlyMutant(...), [...$report->mutants()]),
            'tests' => array_map(self::costlyTest(...), [...$report->tests()]),
            'wallSeconds' => $report->spent()->wall()->seconds(),
            'runnerSeconds' => $report->spent()->runner()->seconds(),
            'jobs' => $report->jobs(),
            'rounds' => $report->rounds(),
            'unjudged' => $report->unjudged(),
            'coreSeconds' => $report->coreSeconds()->seconds(),
            'suiteSeconds' => $report->suite()->seconds(),
            ...$multiple instanceof NotGiven ? [] : ['suiteMultiple' => $multiple],
        ];
    }

    /** @return RouteEntry */
    private static function route(RouteCost $route): array
    {
        $each = $route->perMutant();

        return [
            'route' => $route->route()->value,
            'mutants' => $route->mutants(),
            'seconds' => $route->seconds()->seconds(),
            'coreSeconds' => $route->coreSeconds()->seconds(),
            ...$each instanceof Seconds ? ['coreSecondsPerMutant' => $each->seconds()] : [],
        ];
    }

    /** @return CostlyMutantEntry */
    private static function costlyMutant(CostlyMutant $costly): array
    {
        $mutant = $costly->mutant();
        $route = $costly->route();

        return [
            'id' => $mutant->id()->value(),
            'file' => $mutant->location()->file()->value(),
            'line' => $mutant->location()->start()->number(),
            'mutator' => $mutant->mutator(),
            'seconds' => $costly->seconds()->seconds(),
            ...$route instanceof Route ? ['route' => $route->value] : [],
        ];
    }

    /** @return CostlyTestEntry */
    private static function costlyTest(CostlyTest $costly): array
    {
        return [
            'test' => $costly->test()->value(),
            'ownSeconds' => $costly->own()->seconds(),
            'mutants' => $costly->mutants(),
            'atMostSeconds' => $costly->atMost()->seconds(),
        ];
    }

    /** @return PruningEntry */
    private static function pruning(PruningAccount $pruning): array
    {
        $mutators = [];

        foreach ($pruning->mutators() as $name) {
            $last = $pruning->lastOf(RunnerMutatorName::of($name));
            $mutators[] = [
                'name' => $name,
                'window' => $pruning->window()->mutants(),
                ...$last instanceof NotGiven ? [] : ['lastSurvivor' => $last],
            ];
        }

        return [
            'mutators' => $mutators,
            'units' => $pruning->units(),
            'carried' => $pruning->carried(),
            'auditSeconds' => $pruning->audit()->seconds(),
            'savedSeconds' => $pruning->saved()->seconds(),
        ];
    }

    /** @return Run */
    private static function run(Verdict $verdict, RunTimings $timings): array
    {
        $plan = $timings->plan();
        $judged = $timings->verdict();
        $shards = [];
        $units = [];

        foreach (Origin::cases() as $origin) {
            $units[$origin->value] = 0;
        }

        foreach ($verdict->trees()->units() as $unit) {
            ++$units[$unit->origin()->value];
        }

        foreach ($timings->shards() as $shard) {
            $shards[] = [
                'shard' => $shard->shard(),
                'start' => $shard->whole()->start()->value(),
                'seconds' => $shard->whole()->duration()->seconds(),
                StepsRecord::SECTION => StepsRecord::of($shard->steps()),
            ];
        }

        return [
            'id' => $timings->run(),
            'traceId' => $timings->traceId(),
            'phases' => self::phases($plan, $judged),
            'shards' => $shards,
            'units' => $units,
            'wallSeconds' => $timings->spent()->wall()->seconds(),
            'runnerSeconds' => $timings->spent()->runner()->seconds(),
            'measured' => $timings->spent()->isMeasured(),
        ];
    }

    /**
     * The plan's and the verdict's phases where they were timed; an empty object where neither was.
     *
     * @return array{plan?: Timed, verdict?: Timed}|stdClass
     */
    private static function phases(Phase|Unmeasured $plan, Phase|Unmeasured $verdict): array|stdClass
    {
        $phases = [
            ...$plan instanceof Phase ? ['plan' => self::timed($plan)] : [],
            ...$verdict instanceof Phase ? ['verdict' => self::timed($verdict)] : [],
        ];

        return $phases === [] ? new stdClass() : $phases;
    }

    /** @return Timed */
    private static function timed(Phase $phase): array
    {
        return ['start' => $phase->start()->value(), 'seconds' => $phase->duration()->seconds()];
    }

    /** @return CostEntry */
    private static function cost(Cost $cost): array
    {
        $price = $cost->price();

        return [
            'planned' => self::figure($cost->planned(), $price),
            'measured' => self::figure($cost->measured(), $price),
            'spared' => ['seconds' => $cost->spared()->seconds(), ...self::price($cost->spared(), $price)],
            'setupEstimated' => $cost->isSetupEstimated(),
            ...$price instanceof Rate ? ['perRunnerMinute' => self::money($price->perRunnerMinute())] : [],
        ];
    }

    /** @return Figure */
    private static function figure(RunTime $time, Rate|Unpriced $price): array
    {
        return [
            'wallSeconds' => $time->wall()->seconds(),
            'runnerSeconds' => $time->runner()->seconds(),
            ...self::price($time->runner(), $price),
        ];
    }

    /** @return array{price?: Priced} */
    private static function price(Seconds $runner, Rate|Unpriced $price): array
    {
        if ($price instanceof Unpriced) {
            return [];
        }

        return ['price' => self::money($price->of($runner))];
    }

    /** @return Priced */
    private static function money(Money $money): array
    {
        return ['amount' => $money->amount(), 'currency' => $money->currency()];
    }

    /** @return Saved */
    private static function savings(Savings $savings): array
    {
        return [
            'fullRun' => [
                'seconds' => $savings->fullRun()->seconds(),
                'measuredPercent' => $savings->measured()->wholePercent(),
            ],
            'saved' => [
                'reachSeconds' => $savings->reach()->seconds(),
                'proofsSeconds' => $savings->proofs()->seconds(),
            ],
            ...$savings->isSharded()
                ? ['sharding' => [
                    'waitSavedSeconds' => $savings->waitSaved()->seconds(),
                    'setupSeconds' => $savings->shardingSetup()->seconds(),
                ]]
                : [],
        ];
    }
}
