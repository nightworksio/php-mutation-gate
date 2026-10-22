<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Sample\Estimate;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;

/**
 * What the JSON report says of a sampled run (ADR-0020, decision 30): each
 * tree's `estimate`, its score, its interval, the confidence it is held at
 * and the units behind it, and the run's `sample`, the seed it was drawn
 * from and the confidence and margin it was sized for. A run that was not
 * sampled says neither.
 *
 * @internal the shape of the report's `estimate` and `sample` fields
 *
 * @phpstan-type EstimateEntry array{
 *     score?: float,
 *     lower: float,
 *     upper: float,
 *     confidence: int,
 *     units: array{sampled: int, exact: int, total: int},
 * }
 * @phpstan-type SampleEntry array{seed: string, confidence: int, margin: float}
 */
final readonly class EstimateJson
{
    /** @return array{estimate?: EstimateEntry} */
    public static function tree(TreeVerdict $tree): array
    {
        $estimate = $tree->estimate();

        return $estimate instanceof Estimate ? ['estimate' => self::of($estimate)] : [];
    }

    /** @return array{sample?: SampleEntry} */
    public static function sample(Sample $sample): array
    {
        return $sample->isDrawn()
            ? ['sample' => [
                'seed' => $sample->seed(),
                'confidence' => $sample->confidence()->value,
                'margin' => $sample->margin(),
            ]]
            : [];
    }

    /** @return EstimateEntry */
    private static function of(Estimate $estimate): array
    {
        $score = $estimate->score();

        return [
            ...$score instanceof Score ? ['score' => $score->percent()] : [],
            'lower' => self::percent($estimate->lower()),
            'upper' => self::percent($estimate->upper()),
            'confidence' => $estimate->sample()->confidence()->value,
            'units' => [
                'sampled' => $estimate->sampledUnits(),
                'exact' => $estimate->exactUnits(),
                'total' => $estimate->totalUnits(),
            ],
        ];
    }

    private static function percent(int $hundredths): float
    {
        return Score::ofHundredths($hundredths)->percent();
    }
}
