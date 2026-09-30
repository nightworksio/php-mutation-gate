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
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A mutant of a file by a mutator, with a status and, where given, the seconds it was allowed. */
function retried(string $file, string $mutator, int $line, MutantStatus $status, float $limit = 0.0): Mutant
{
    $diff = sprintf("@@ @@\n-a %d\n+b %d", $line, $line);
    $mutant = Mutant::of(
        MutantId::hash(Path::of($file), $mutator, $diff, 0),
        sprintf('n%d', $line),
        Location::of(Path::of($file), Line::of($line), Unreported::line()),
        Mutation::of($mutator, MutatorFamily::Arithmetic, $diff),
        $status,
        Unmeasured::duration(),
    );

    return $limit > 0.0 ? $mutant->withLimit(Seconds::of($limit)) : $mutant;
}

it('takes a timed-out or skipped mutant only where the cap decided its limit, and every other mutant', function (): void {
    $retrial = Retrial::under(Seconds::of(10.0));

    expect($retrial->takes(retried('a.php', 'Plus', 1, MutantStatus::TimedOut, 10.0)))->toBeTrue()
        ->and($retrial->takes(retried('a.php', 'Plus', 1, MutantStatus::Skipped, 10.0)))->toBeTrue()
        ->and($retrial->takes(retried('a.php', 'Plus', 1, MutantStatus::TimedOut, 11.0)))->toBeTrue()
        ->and($retrial->takes(retried('a.php', 'Plus', 1, MutantStatus::TimedOut, 9.99)))->toBeFalse()
        ->and($retrial->takes(retried('a.php', 'Plus', 1, MutantStatus::Skipped, 9.99)))->toBeFalse()
        ->and($retrial->takes(retried('a.php', 'Plus', 1, MutantStatus::TimedOut)))->toBeFalse()
        ->and($retrial->takes(retried('a.php', 'Plus', 1, MutantStatus::Survived)))->toBeTrue()
        ->and($retrial->takes(retried('a.php', 'Plus', 1, MutantStatus::Killed, 6.0)))->toBeTrue();
});

it('runs each file and mutator of the mutants it takes once', function (): void {
    $retrial = Retrial::under(Seconds::of(10.0));
    $runs = $retrial->runs(Mutants::of(
        retried('a.php', 'Plus', 1, MutantStatus::TimedOut, 10.0),
        retried('a.php', 'Plus', 2, MutantStatus::Skipped, 10.0),
        retried('a.php', 'Minus', 3, MutantStatus::TimedOut, 10.0),
        retried('b.php', 'Plus', 4, MutantStatus::TimedOut, 10.0),
        retried('c.php', 'Plus', 5, MutantStatus::TimedOut, 6.0),
        retried('d.php', 'Plus', 6, MutantStatus::Survived),
    ));

    expect($runs)->toEqual([
        [Path::of('a.php'), 'Plus'],
        [Path::of('a.php'), 'Minus'],
        [Path::of('b.php'), 'Plus'],
        [Path::of('d.php'), 'Plus'],
    ]);
});

it('replaces each mutant it took by its result run again, keeps the rest, and leaves unjudged one run again made no more', function (): void {
    $retrial = Retrial::under(Seconds::of(10.0));
    $timedOut = retried('a.php', 'Plus', 1, MutantStatus::TimedOut, 10.0);
    $lost = retried('a.php', 'Plus', 2, MutantStatus::Skipped, 10.0);
    $formula = retried('a.php', 'Plus', 3, MutantStatus::TimedOut, 6.0);
    $again = retried('a.php', 'Plus', 1, MutantStatus::Killed, 20.0);

    expect($retrial->matched(Mutants::of($formula, $timedOut, $lost), Mutants::of($again)))->toEqual(Mutants::of(
        $formula,
        $again,
        Mutant::of($lost->id(), 'n2', $lost->location(), $lost->mutation(), MutantStatus::Unjudged, Unmeasured::duration())
            ->because(Reason::that('Run again, Infection made no mutant with this id.')),
    ));
});
