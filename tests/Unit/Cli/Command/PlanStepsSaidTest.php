<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\PlanStepsSaid;
use NightWorksIO\MutationGate\Core\Config\Planning;
use NightWorksIO\MutationGate\Core\Cost\PlanLap;
use NightWorksIO\MutationGate\Core\Cost\PlanLaps;
use NightWorksIO\MutationGate\Core\Cost\PlanStep;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Plan\PlanStepsRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    Scratch::sweep();
});

$laps = static fn(): PlanLaps => PlanLaps::of(
    PlanLap::of(PlanStep::Composing, Seconds::of(0.0), Seconds::of(0.04)),
    PlanLap::of(PlanStep::Map, Seconds::of(0.04), Seconds::of(41.26)),
    PlanLap::of(PlanStep::Handoff, Seconds::of(41.3), Seconds::of(0.06)),
    PlanLap::of(PlanStep::WarmUp, Seconds::of(41.36), Seconds::of(88.0)),
);

it('says how long the plan and each step of a tenth of a second or more took, and records every step beside the plan', function () use ($laps): void {
    $project = Scratch::directory();
    $said = new BufferedOutput();

    $code = PlanStepsSaid::of(Planning::standard(), Directory::at($project), $laps(), $said);
    $recorded = (string) file_get_contents(sprintf('%s/.mutation-gate/plan-steps.json', $project));

    expect($code)->toBe(0)
        ->and($said->fetch())->toBe(<<<'SAID'
            The plan took 129.4 s, in these steps, each that took less than 0.1 s left out:
              map                 41.3 s
              handoff              0.1 s
              warm-up             88.0 s
            Wrote .mutation-gate/plan-steps.json.

            SAID)
        ->and(PlanStepsRecord::read(Node::decode($recorded)))->toEqual($laps());
});

it('says and records nothing where timing.planSteps is off', function () use ($laps): void {
    $project = Scratch::directory();
    $said = new BufferedOutput();

    $code = PlanStepsSaid::of(
        Planning::standard()->over(Planning::of(planSteps: false)),
        Directory::at($project),
        $laps(),
        $said,
    );

    expect($code)->toBe(0)
        ->and($said->fetch())->toBe('')
        ->and(file_exists(sprintf('%s/.mutation-gate/plan-steps.json', $project)))->toBeFalse();
});

it('fails the plan where the record cannot be written', function () use ($laps): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate', 'a file where the directory should be');
    $said = new BufferedOutput();

    expect(PlanStepsSaid::of(Planning::standard(), Directory::at($project), $laps(), $said))->not->toBe(0)
        ->and($said->fetch())->not->toContain('The plan took');
});
