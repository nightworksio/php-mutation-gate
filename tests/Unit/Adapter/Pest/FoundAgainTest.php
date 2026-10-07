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
