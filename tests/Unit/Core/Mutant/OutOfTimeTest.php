<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('says what the budget ran out before, and the command that judges it', function (OutOfTime $before, string $said): void {
    expect($before->reason())->toEqual(Reason::that(
        sprintf('The time budget ran out before %s. More time judges it: vendor/bin/mutation-gate run --budget=<duration>', $said),
    ));
})->with([
    'mutating' => [OutOfTime::BeforeMutating, 'this run mutated its unit'],
    'retrying' => [OutOfTime::BeforeRetrying, 'it could run again with a doubled limit'],
    'confirming' => [OutOfTime::BeforeConfirming, 'its survival could be confirmed'],
]);

it('knows a mutant or a kill a time budget left unjudged by its reason, and no other', function (Mutant|ProvedKill $mutant, bool $left): void {
    expect(OutOfTime::left($mutant))->toBe($left);
})->with(function (): array {
    $mutant = static fn(MutantStatus $status): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '1', 0),
        '1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        $status,
        Unmeasured::duration(),
    );
    $kill = ProvedKill::of(MutantId::hash(Path::of('src/Money.php'), 'Plus', '2', 0), Path::of('src/Money.php'), Line::of(2), 'Plus', TestIds::none());

    return [
        'a timeout it had no time to run again' => [$mutant(MutantStatus::TimedOut)->unjudged(OutOfTime::BeforeRetrying), true],
        'a survivor it had no time to confirm' => [$mutant(MutantStatus::Survived)->unjudged(OutOfTime::BeforeConfirming), true],
        'a kill that no longer stands' => [$kill->unjudged(OutOfTime::BeforeMutating), true],
        'a kill' => [$kill, false],
        'a mutant the runner left unjudged for its own reason' => [$mutant(MutantStatus::Unjudged)->because(Reason::that('No test could be named.')), false],
        'a mutant unjudged with no reason' => [$mutant(MutantStatus::Unjudged), false],
        'a survivor whose reason reads like the budget\'s' => [$mutant(MutantStatus::Survived)->because(OutOfTime::BeforeConfirming->reason()), false],
    ];
});
