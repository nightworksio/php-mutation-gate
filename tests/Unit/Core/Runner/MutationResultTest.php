<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
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

it('keeps the evidence of each mutant it is given again as it was, and none of one put in its place', function (): void {
    $killed = static fn(string $mutator, MutantStatus $status): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), $mutator, "-a + b\n+a - b", 0),
        '8',
        Location::of(Path::of('src/Money.php'), Line::of(9), Line::of(9)),
        Mutation::of($mutator, MutatorFamily::Arithmetic, "-a + b\n+a - b"),
        $status,
        Seconds::of(0.5),
    );
    $kept = $killed('Plus', MutantStatus::Killed);
    $replaced = $killed('Minus', MutantStatus::Killed);
    $evidence = Evidence::none()->withPrefix(Prefix::at(2));
    $result = MutationResult::of(Mutants::of($kept, $replaced), 0)
        ->withEvidence(Evidences::none()->with($kept->id(), $evidence)->with($replaced->id(), $evidence));
    $again = $result->withMutants(Mutants::of($kept, $killed('Minus', MutantStatus::Survived)));

    expect($again->evidence()->of($kept->id()))->toBe($evidence)
        ->and($again->evidence()->of($replaced->id()))->toEqual(Evidence::none())
        ->and($again->evidence())->toHaveCount(1);
});
