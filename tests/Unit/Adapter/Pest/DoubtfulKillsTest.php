<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\DoubtfulKills;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily as Family;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('doubts every kill that names no killer where the results cannot be read, and no other', function (): void {
    $survivor = Verdicts::mutant('src/Money.php:7', 'TrueValue', Family::Literal, '-a');
    $killerless = Mutant::of(
        $survivor->id(),
        $survivor->nativeId(),
        $survivor->location(),
        $survivor->mutation(),
        MutantStatus::Killed,
        Unmeasured::duration(),
    );
    $result = MutationResult::of(Mutants::of($survivor, $killerless, Verdicts::killed()), 0);

    $doubtful = DoubtfulKills::in($result, sprintf('%s/missing.jsonl', Scratch::directory()));

    expect([...$doubtful])->toEqual([$killerless]);
});
