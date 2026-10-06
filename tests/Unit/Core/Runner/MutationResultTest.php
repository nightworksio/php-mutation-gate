<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
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
