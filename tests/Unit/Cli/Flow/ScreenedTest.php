<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Checked;
use NightWorksIO\MutationGate\Cli\Flow\Confirmed;
use NightWorksIO\MutationGate\Cli\Flow\Screened;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedIds;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** A mutant of src/Money.php on this line, ended so. */
$at = static function (int $line, MutantStatus $status): Mutant {
    $mutant = Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::None, '');

    return Mutant::of($mutant->id(), $mutant->nativeId(), $mutant->location(), $mutant->mutation(), $status, $mutant->duration());
};

/** What screening these mutants came to: the first flaky, the second proven equivalent, the third checked and left unsettled. */
$screened = static function (Mutant ...$mutants): Screened {
    $checks = SurvivorChecks::none()->leaving(UncheckedSurvivor::of(Unchecked::Failed, Path::of('src/Money.php')));

    return Screened::of(
        new Checked(Mutants::of(...$mutants), $checks, Mutants::of($mutants[2]), UncheckedIds::none()->with(Unchecked::Failed, $mutants[2]->id())),
        new Confirmed(Mutants::of(...$mutants), MutantIds::of($mutants[0]->id())),
        MutantIds::of($mutants[1]->id()),
    );
};

it('settles every mutant a control left but those that survive there and did not before', function () use ($at, $screened): void {
    $first = $screened($at(1, MutantStatus::Survived), $at(2, MutantStatus::TimedOut), $at(3, MutantStatus::Survived));
    $controlled = Mutants::of($at(1, MutantStatus::Survived), $at(2, MutantStatus::Survived), $at(3, MutantStatus::Killed));

    expect([...$first->settledIn($controlled)])->toEqual([$at(1, MutantStatus::Survived)->id(), $at(3, MutantStatus::Killed)->id()]);
});

it('keeps what both screenings found, and the mutants the second left', function () use ($at, $screened): void {
    $first = $screened($at(1, MutantStatus::Survived), $at(2, MutantStatus::Survived), $at(3, MutantStatus::Survived));
    $late = $screened($at(4, MutantStatus::Survived), $at(5, MutantStatus::Survived), $at(6, MutantStatus::Survived));
    $both = $first->then($late);

    expect($both->mutants)->toBe($late->mutants)
        ->and([...$both->flaky])->toEqual([$at(1, MutantStatus::Survived)->id(), $at(4, MutantStatus::Survived)->id()])
        ->and([...$both->equivalent])->toEqual([$at(2, MutantStatus::Survived)->id(), $at(5, MutantStatus::Survived)->id()])
        ->and([...$both->checked])->toEqual([$at(3, MutantStatus::Survived)->id(), $at(6, MutantStatus::Survived)->id()])
        ->and([...$both->unchecked->for(Unchecked::Failed)])->toEqual([$at(3, MutantStatus::Survived)->id(), $at(6, MutantStatus::Survived)->id()])
        ->and(count([...$both->checks]))->toBe(2)
        ->and($first->controlled($late->mutants)->mutants)->toBe($late->mutants)
        ->and([...$first->controlled($late->mutants)->flaky])->toEqual([...$first->flaky]);
});
