<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\NotRecorded;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Recording;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

$mutant = static fn(int $line, MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf('-%d', $line), 0),
    sprintf('%d', $line),
    Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line)),
    $status,
    Unmeasured::duration(),
);
$makeRun = static fn(): Run => Run::of('github:5813/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));

it('records a unit that ran to the end, survivors, timeouts and all', function () use ($mutant, $makeRun): void {
    $run = $makeRun();

    $mutants = Mutants::of($mutant(1, MutantStatus::Killed), $mutant(2, MutantStatus::Survived), $mutant(3, MutantStatus::TimedOut));

    $inputs = Inputs::of(Digest::sha256Of('source'), Digest::sha256Of('mutation'));

    expect(Recording::of(Digest::of('9c1e'), Path::of('src/Money.php'), $mutants, MutantIds::none(), $run, $inputs))
        ->toEqual(Proof::of(Digest::of('9c1e'), Path::of('src/Money.php'), $mutants, $run)->withInputs($inputs));
});

it('records nothing of a unit with no key', function () use ($mutant, $makeRun): void {
    $run = $makeRun();

    $mutants = Mutants::of($mutant(1, MutantStatus::Killed));

    expect(Recording::of(Unkeyed::because('There is no git.'), Path::of('src/Money.php'), $mutants, MutantIds::none(), $run, Undigested::proof()))
        ->toEqual(NotRecorded::because('There is no git.'));
});

it('records nothing of a unit with a mutant left unjudged', function () use ($mutant, $makeRun): void {
    $run = $makeRun();

    $mutants = Mutants::of($mutant(1, MutantStatus::Killed), $mutant(2, MutantStatus::Unjudged));

    expect(Recording::of(Digest::of('9c1e'), Path::of('src/Money.php'), $mutants, MutantIds::none(), $run, Undigested::proof()))
        ->toEqual(NotRecorded::because('src/Money.php did not run to the end: 1 of its mutants are unjudged or flaky.'));
});

it('records nothing of a unit with a flaky mutant, counting each unfinished mutant once', function () use ($mutant, $makeRun): void {
    $run = $makeRun();

    $flaky = $mutant(2, MutantStatus::Survived);
    $unjudged = $mutant(3, MutantStatus::Unjudged);
    $mutants = Mutants::of($mutant(1, MutantStatus::Killed), $flaky, $unjudged);

    expect(Recording::of(Digest::of('9c1e'), Path::of('src/Money.php'), $mutants, MutantIds::of($flaky->id(), $unjudged->id()), $run, Undigested::proof()))
        ->toEqual(NotRecorded::because('src/Money.php did not run to the end: 2 of its mutants are unjudged or flaky.'));
});
