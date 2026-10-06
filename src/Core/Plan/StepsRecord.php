<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;

use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The steps a shard's time went to, as its result holds them under
 * `measured.steps` (ADR-0016, decision 19): each its `step`, the seconds
 * after the shard began that it started, `since`, the seconds it took, and,
 * where it handled more than one, how many as `count`. A shard that timed no
 * step holds none.
 *
 * @internal the shape of a shard result's `measured.steps`
 */
final readonly class StepsRecord
{
    public const string SECTION = 'steps';

    private const string STEP = 'step';

    private const string SINCE = 'since';

    private const string SECONDS = 'seconds';

    private const string COUNT = 'count';

    /** @return list<array{step: string, since: float, seconds: float, count?: int}> */
    public static function of(StepTimes $steps): array
    {
        return array_map(
            static fn(StepTime $step): array => [
                self::STEP => $step->step()->value,
                self::SINCE => $step->since()->seconds(),
                self::SECONDS => $step->took()->seconds(),
                ...$step->count() === 1 ? [] : [self::COUNT => $step->count()],
            ],
            [...$steps],
        );
    }

    /**
     * The steps a result holds, none where it holds none.
     *
     * @throws NotInShape
     */
    public static function read(Node $section): StepTimes
    {
        if (! $section->isPresent()) {
            return StepTimes::none();
        }

        return StepTimes::of(...array_map(self::stepIn(...), $section->items()));
    }

    /** @throws NotInShape */
    private static function stepIn(Node $item): StepTime
    {
        $count = $item->field(self::COUNT);

        return StepTime::of(
            Step::tryFrom($item->field(self::STEP)->text())
                ?? throw NotInShape::at($item->field(self::STEP)->at(), 'a step'),
            Seconds::of($item->field(self::SINCE)->number()),
            Seconds::of($item->field(self::SECONDS)->number()),
            $count->isPresent() ? $count->integer() : 1,
        );
    }
}
