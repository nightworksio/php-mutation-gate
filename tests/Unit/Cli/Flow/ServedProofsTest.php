<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\ServedProofs;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ProofEntry;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\TakenProofs;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Tests\Fakes\KeepsProofsFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ServedStateFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\OutbreakStandIn;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A proof of this unit, under the digest of its name. */
function servedProof(string $unit): Proof
{
    $run = Run::of('kept', Instant::at(new DateTimeImmutable('2026-11-01T00:00:00Z')), Digest::sha256Of('base'));

    return Proof::of(Digest::sha256Of($unit), Path::of($unit), Mutants::none(), $run);
}

/**
 * The units whose proofs the daemon serves a run, which takes these proofs, of src/A.php and src/B.php, the default
 * branch's ledger holding src/A.php's, its own branch's src/B.php's where it runs on one, and the daemon both.
 *
 * @return list<string>
 */
function servedUnits(TakenProofs $taken, KeepsProofsFake $state, bool $served = true, bool $ownHolds = false): array
{
    $project = Flows::project();
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(servedProof('src/A.php')));
    $state->kept([
        Digest::sha256Of('src/A.php')->value() => ProofEntry::text(servedProof('src/A.php')),
        Digest::sha256Of('src/B.php')->value() => ProofEntry::text(servedProof('src/B.php')),
    ]);
    $engine = Outbreak::at(OutbreakStandIn::answering('{"outbreak":1}')->binary(), $project);
    $adapters = Flows::adapters($project, [], $served ? $engine->servedBy(new ServedStateFake($state)) : $engine);
    $keys = Keys::none()
        ->with(Path::of('src/A.php'), Digest::sha256Of('src/A.php'))
        ->with(Path::of('src/B.php'), Digest::sha256Of('src/B.php'));
    $plan = $ownHolds ? Planned::twoShards()->on(RunOn::at(Scope::branch('feature'), Scope::branch('main'))) : Planned::twoShards();

    if ($ownHolds) {
        $store->write(Scope::branch('feature'), Ledger::empty()->withProof(servedProof('src/B.php')));
    }

    $ledgers = Ledgers::read($store, Standing::planned($plan), Writing::Auto, $taken);

    return array_map(static fn(Proof $proof): string => $proof->unit()->value(), [...ServedProofs::read($adapters, $taken, $ledgers, $keys)]);
}

it('takes from the daemon the proof of each unit no ledger read holds one of', function (): void {
    expect(servedUnits(TakenProofs::Every, KeepsProofsFake::running()))->toBe(['src/B.php']);
});

it('takes none where the run takes the default branch\'s proofs alone, no daemon serves, the daemon keeps none, or its own ledger holds the rest', function (
    TakenProofs $taken,
    bool $served,
    bool $keeps,
    bool $ownHolds,
): void {
    expect(servedUnits($taken, $keeps ? KeepsProofsFake::running() : KeepsProofsFake::none(), $served, $ownHolds))->toBe([]);
})->with([
    'the default branch\'s alone' => [TakenProofs::DefaultBranchOnly, true, true, false],
    'no daemon' => [TakenProofs::Every, false, true, false],
    'a daemon that keeps none' => [TakenProofs::Every, true, false, false],
    'its own ledger holding the rest' => [TakenProofs::Every, true, true, true],
]);
