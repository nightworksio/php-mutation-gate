<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\FoundAgain;
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

/** A mutant of src/Money.php with Pest's id n1 on this line, under the gate's id of this occurrence. */
$mutant = static fn(int $line, int $occurrence): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a + b\n+a - b", $occurrence),
    'n1',
    Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, "-a + b\n+a - b"),
    MutantStatus::Killed,
    Seconds::of(0.5),
);

it('hands the evidence of each kill found again to the mutant asked for, under its id, and none to one not found', function () use ($mutant): void {
    $asked = $mutant(11, 0);
    $missing = $mutant(30, 1);
    $found = $mutant(11, 2);
    $evidence = Evidence::none()->withPrefix(Prefix::at(3));
    $again = MutationResult::of(Mutants::of($found), 0)->withEvidence(Evidences::none()->with($found->id(), $evidence));

    $handed = FoundAgain::evidenceAmong(Mutants::of($asked, $missing), $again);

    expect($handed->of($asked->id()))->toBe($evidence)
        ->and($handed->of($found->id()))->toEqual(Evidence::none())
        ->and($handed)->toHaveCount(1);
});

it('matches each mutant asked for to the first found again on its line that no earlier one took, past one not found', function () use ($mutant): void {
    [$first, $missing, $second] = [$mutant(11, 0), $mutant(30, 1), $mutant(11, 3)];
    [$foundFirst, $foundSecond] = [$mutant(11, 4), $mutant(11, 5)];
    [$early, $late] = [Evidence::none()->withPrefix(Prefix::at(1)), Evidence::none()->withPrefix(Prefix::at(2))];
    $again = MutationResult::of(Mutants::of($foundFirst, $foundSecond), 0)
        ->withEvidence(Evidences::none()->with($foundFirst->id(), $early)->with($foundSecond->id(), $late));

    $handed = FoundAgain::evidenceAmong(Mutants::of($first, $missing, $second), $again);

    expect($handed->of($first->id()))->toBe($early)
        ->and($handed->of($second->id()))->toBe($late)
        ->and($handed)->toHaveCount(2);
});
