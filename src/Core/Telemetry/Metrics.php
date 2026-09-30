<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Telemetry;

use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * What a verdict emits as metrics: each tree's and each new-code set's
 * score, the mutants by status, the units by how their result came, each
 * phase's duration and the runner minutes, the last two for a timed run
 * only. No attribute names a mutant or a file, so there are at most as many
 * points as trees times statuses (ADR-0016, decisions 15 and 16).
 */
final readonly class Metrics
{
    public const string TREE = 'mutation_gate.tree';

    public const string STATUS = 'mutation_gate.status';

    public const string PHASE = 'mutation_gate.phase';

    /** @return list<Metric> */
    public static function of(Verdict $verdict): array
    {
        $timings = $verdict->account()->timings();

        return [
            Metric::of('mutation_gate.score', '%', MetricKind::Gauge, self::scores($verdict)),
            Metric::of('mutation_gate.mutants', '1', MetricKind::Sum, self::mutants($verdict)),
            Metric::of('mutation_gate.units', '1', MetricKind::Sum, self::units($verdict)),
            ...$timings instanceof RunTimings ? [
                Metric::of('mutation_gate.duration', 's', MetricKind::Gauge, self::durations($timings)),
                Metric::of(
                    'mutation_gate.runner_minutes',
                    'min',
                    MetricKind::Sum,
                    [DataPoint::of($timings->spent()->runner()->inMinutes(), [])],
                ),
            ] : [],
        ];
    }

    /** @return list<DataPoint> */
    private static function scores(Verdict $verdict): array
    {
        $points = [];

        foreach ($verdict->trees() as $tree) {
            $score = $tree->score();
            $points = $score instanceof Score
                ? [...$points, DataPoint::of($score->percent(), [self::TREE => $tree->tree()->path()->value()])]
                : $points;
        }

        foreach ($verdict->newCode() as $set) {
            $score = $set->score();
            $name = sprintf('new code in %s', $set->package()->path()->value());
            $points = $score instanceof Score
                ? [...$points, DataPoint::of($score->percent(), [self::TREE => $name])]
                : $points;
        }

        return $points;
    }

    /** @return list<DataPoint> */
    private static function mutants(Verdict $verdict): array
    {
        $counts = $verdict->trees()->mutants()->counts();
        $points = [];

        foreach (MutantJudgement::cases() as $judgement) {
            $number = $counts->number($judgement);
            $points = $number > 0 ? [...$points, DataPoint::of($number, [self::STATUS => $judgement->value])] : $points;
        }

        return $points;
    }

    /** @return list<DataPoint> */
    private static function units(Verdict $verdict): array
    {
        $points = [];

        foreach (Origin::cases() as $origin) {
            $number = 0;

            foreach ($verdict->trees()->units() as $unit) {
                $number += $unit->origin() === $origin ? 1 : 0;
            }

            $points[] = DataPoint::of($number, [self::STATUS => $origin->value]);
        }

        return $points;
    }

    /** @return list<DataPoint> */
    private static function durations(RunTimings $timings): array
    {
        $plan = $timings->plan();
        $verdict = $timings->verdict();
        $points = $plan instanceof Phase ? [self::duration($plan, 'plan', [])] : [];

        foreach ($timings->shards() as $shard) {
            $points[] = self::duration($shard->openingRun(), 'opening run', [Trace::SHARD => $shard->shard()]);
            $points[] = self::duration($shard->mutate(), 'mutate', [Trace::SHARD => $shard->shard()]);
        }

        return $verdict instanceof Phase ? [...$points, self::duration($verdict, 'verdict', [])] : $points;
    }

    /** @param array<string, int> $shard */
    private static function duration(Phase $phase, string $name, array $shard): DataPoint
    {
        return DataPoint::of($phase->duration()->seconds(), [self::PHASE => $name, ...$shard]);
    }
}
