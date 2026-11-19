<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;

use NightWorksIO\MutationGate\Core\Cost\PlanLap;
use NightWorksIO\MutationGate\Core\Cost\PlanLaps;
use NightWorksIO\MutationGate\Core\Cost\PlanStep;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The steps the plan's time went to, as it records them beside the plan
 * (ADR-0220): the `seconds` from its start to the end of its last step, and
 * each step as `steps`, its `step`, the seconds after the plan began that
 * it started, `since`, and the seconds it took.
 *
 * @internal the shape of `.mutation-gate/plan-steps.json`
 */
final readonly class PlanStepsRecord
{
    private const string STEPS = StepsRecord::SECTION;

    private const string STEP = StepsRecord::STEP;

    private const string SINCE = StepsRecord::SINCE;

    private const string SECONDS = StepsRecord::SECONDS;

    public static function of(PlanLaps $laps): Json
    {
        return Json::object(
            Member::of(self::SECONDS, $laps->whole()->seconds()),
            Member::of(self::STEPS, Json::items(...array_map(self::rowOf(...), [...$laps]))),
        );
    }

    /**
     * The steps a record holds.
     *
     * @throws NotInShape
     */
    public static function read(Node $record): PlanLaps
    {
        return PlanLaps::of(...array_map(self::lapIn(...), $record->field(self::STEPS)->items()));
    }

    private static function rowOf(PlanLap $lap): Json
    {
        return Json::object(
            Member::of(self::STEP, $lap->step()->value),
            Member::of(self::SINCE, $lap->since()->seconds()),
            Member::of(self::SECONDS, $lap->took()->seconds()),
        );
    }

    /** @throws NotInShape */
    private static function lapIn(Node $item): PlanLap
    {
        return PlanLap::of(
            PlanStep::tryFrom($item->field(self::STEP)->text())
                ?? throw NotInShape::at($item->field(self::STEP)->at(), 'a step of the plan'),
            Seconds::of($item->field(self::SINCE)->number()),
            Seconds::of($item->field(self::SECONDS)->number()),
        );
    }
}
