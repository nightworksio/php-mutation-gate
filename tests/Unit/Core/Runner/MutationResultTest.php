<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

it('holds the mutants a run recorded and how many it skipped without a record', function (): void {
    $mutants = Mutants::none();
    $result = MutationResult::of($mutants, 3);

    expect($result->mutants())->toBe($mutants)
        ->and($result->skipped())->toBe(3);
});

it('warns of nothing unless a runner says, and keeps each warning it adds after those it had', function (): void {
    $result = MutationResult::of(Mutants::none(), 0);
    $warned = $result->withWarnings(Warnings::of(Warning::that('first')))->withWarnings(Warnings::of(Warning::that('second')));

    expect($result->warnings())->toEqual(Warnings::none())
        ->and(array_map(static fn(Warning $warning): string => $warning->text(), [...$warned->warnings()]))
        ->toBe(['first', 'second'])
        ->and($warned->mutants())->toBe($result->mutants());
});

it('names the steps its time went to, those before first, and keeps them, its warnings and its skips with other mutants', function (): void {
    $step = static fn(Step $step): StepTimes => StepTimes::of(StepTime::of($step, Seconds::of(0.0), Seconds::of(1.0)));
    $result = MutationResult::of(Mutants::none(), 2)
        ->withWarnings(Warnings::of(Warning::that('warned')))
        ->withSteps($step(Step::Mutation))
        ->withSteps($step(Step::Reading))
        ->withStepsBefore($step(Step::Coverage));
    $other = Mutants::of();
    $replaced = $result->withMutants($other);

    expect(array_map(static fn(StepTime $time): Step => $time->step(), [...$replaced->steps()]))
        ->toBe([Step::Coverage, Step::Mutation, Step::Reading])
        ->and($replaced->mutants())->toBe($other)
        ->and($replaced->skipped())->toBe(2)
        ->and($replaced->warnings())->toEqual($result->warnings())
        ->and(count(MutationResult::of(Mutants::none(), 0)->steps()))->toBe(0);
});
