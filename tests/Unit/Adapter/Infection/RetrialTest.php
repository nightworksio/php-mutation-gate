<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Retrial;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A mutant of a file by a mutator, with a status. */
function retried(string $file, string $mutator, int $line, MutantStatus $status): Mutant
{
    $diff = sprintf("@@ @@\n-a %d\n+b %d", $line, $line);
    return Mutant::of(
        MutantId::hash(Path::of($file), $mutator, $diff, 0),
        sprintf('n%d', $line),
        Location::of(Path::of($file), Line::of($line), Unreported::line()),
        Mutation::of($mutator, MutatorFamily::Arithmetic, $diff),
        $status,
        Unmeasured::duration(),
    );
}

it('runs each file and mutator of the mutants once', function (): void {
    $runs = Retrial::of()->runs(Mutants::of(
        retried('a.php', 'Plus', 1, MutantStatus::Survived),
        retried('a.php', 'Plus', 2, MutantStatus::Survived),
        retried('a.php', 'Minus', 3, MutantStatus::Survived),
        retried('b.php', 'Plus', 4, MutantStatus::Survived),
    ));

    expect($runs)->toEqual([
        [Path::of('a.php'), 'Plus'],
        [Path::of('a.php'), 'Minus'],
        [Path::of('b.php'), 'Plus'],
    ]);
});

it('replaces each mutant by its result run again, and leaves unjudged one run again made no more', function (): void {
    $survived = retried('a.php', 'Plus', 1, MutantStatus::Survived);
    $lost = retried('a.php', 'Plus', 2, MutantStatus::Survived);
    $again = retried('a.php', 'Plus', 1, MutantStatus::Killed);

    expect(Retrial::of()->matched(Mutants::of($survived, $lost), Mutants::of($again)))->toEqual(Mutants::of(
        $again,
        Mutant::of($lost->id(), 'n2', $lost->location(), $lost->mutation(), MutantStatus::Unjudged, Unmeasured::duration())
            ->because(Reason::that('Run again, Infection made no mutant with this id.')),
    ));
});
