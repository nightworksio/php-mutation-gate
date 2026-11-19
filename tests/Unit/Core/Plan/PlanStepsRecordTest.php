<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\PlanLap;
use NightWorksIO\MutationGate\Core\Cost\PlanLaps;
use NightWorksIO\MutationGate\Core\Cost\PlanStep;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Plan\PlanStepsRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('records the plan\'s whole time and each step, and reads them back', function (): void {
    $laps = PlanLaps::of(
        PlanLap::of(PlanStep::Composing, Seconds::of(0.0), Seconds::of(1.5)),
        PlanLap::of(PlanStep::Map, Seconds::of(1.5), Seconds::of(40.25)),
        PlanLap::of(PlanStep::Handoff, Seconds::of(41.75), Seconds::of(90.0)),
    );
    $printed = PlanStepsRecord::of($laps)->printed();

    expect(json_decode($printed, associative: true))->toBe([
        'seconds' => 131.75,
        'steps' => [
            ['step' => 'composing', 'since' => 0.0, 'seconds' => 1.5],
            ['step' => 'map', 'since' => 1.5, 'seconds' => 40.25],
            ['step' => 'handoff', 'since' => 41.75, 'seconds' => 90.0],
        ],
    ])->and(PlanStepsRecord::read(Node::decode($printed)))->toEqual($laps);
});

it('records a plan that timed no step as taking no time', function (): void {
    expect(json_decode(PlanStepsRecord::of(PlanLaps::none())->printed(), associative: true))->toBe(['seconds' => 0.0, 'steps' => []]);
});

it('refuses a step it does not know', function (): void {
    PlanStepsRecord::read(Node::decode('{"seconds": 1, "steps": [{"step": "dreaming", "since": 0, "seconds": 1}]}'));
})->throws(NotInShape::class);
