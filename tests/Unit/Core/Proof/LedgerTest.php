<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('has proved nothing to begin with', function (): void {
    $ledger = Ledger::empty();

    expect($ledger->proofs())->toEqual(Proofs::none())
        ->and($ledger->timings())->toEqual(Timings::none())
        ->and($ledger->lastPassedOn(Revision::ref('main')))->toEqual(CannotTell::because('No commit on main has passed yet.'));
});

it('holds proofs, timings and the newest passing commit on each branch', function (): void {
    $proof = Proof::of(Digest::of('9c1e'), Path::of('src/Money.php'), Mutants::none());
    $timing = Timing::of(Path::of('src/Money.php'), Seconds::of(12.4));
    $ledger = Ledger::empty()
        ->withProof($proof)
        ->withTiming($timing)
        ->withPassed(Revision::ref('main'), Revision::ref('5eeca8f'))
        ->withPassed(Revision::ref('release'), Revision::ref('1a2b3c4'))
        ->withPassed(Revision::ref('main'), Revision::ref('206b4e0'));

    expect($ledger->proofs())->toEqual(Proofs::of($proof))
        ->and($ledger->timings())->toEqual(Timings::of($timing))
        ->and($ledger->lastPassedOn(Revision::ref('main')))->toEqual(Revision::ref('206b4e0'))
        ->and($ledger->lastPassedOn(Revision::ref('release')))->toEqual(Revision::ref('1a2b3c4'));
});

it('leaves the ledger it came from as it was', function (): void {
    $ledger = Ledger::empty();
    $ledger->withProof(Proof::of(Digest::of('9c1e'), Path::of('src/Money.php'), Mutants::none()));
    $ledger->withTiming(Timing::of(Path::of('src/Money.php'), Seconds::of(12.4)));
    $ledger->withPassed(Revision::ref('main'), Revision::ref('5eeca8f'));

    expect($ledger)->toEqual(Ledger::empty());
});
