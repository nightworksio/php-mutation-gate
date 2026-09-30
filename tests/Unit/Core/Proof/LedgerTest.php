<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$proof = static fn(string $key, string $unit): Proof => Proof::of(
    Digest::of($key),
    Path::of($unit),
    Mutants::none(),
    Run::of('local', Moment::at('2026-09-29T20:48:17Z')),
);
$timing = static fn(string $unit, float $seconds, string $at = '2026-09-29T20:00:00Z'): Timing => Timing::of(
    Path::of($unit),
    Seconds::of($seconds),
    'pest',
    Moment::at($at),
);

it('has proved nothing to begin with', function (): void {
    $ledger = Ledger::empty();

    expect($ledger->proofs())->toEqual(Proofs::none())
        ->and($ledger->timings())->toEqual(Timings::none())
        ->and($ledger->lastPassed())->toEqual(CannotTell::because('No commit of this scope has passed yet.'));
});

it('holds proofs, timings and the newest passing commit of its scope', function () use ($proof, $timing): void {
    $ledger = Ledger::empty()
        ->withProof($proof('9c1e', 'src/Money.php'))
        ->withTiming($timing('src/Money.php', 12.4))
        ->withPassed(Revision::ref('5eeca8f'))
        ->withPassed(Revision::ref('206b4e0'));

    expect($ledger->proofs())->toEqual(Proofs::of($proof('9c1e', 'src/Money.php')))
        ->and($ledger->timings())->toEqual(Timings::of($timing('src/Money.php', 12.4)))
        ->and($ledger->lastPassed())->toEqual(Revision::ref('206b4e0'));
});

it('drops the proof under a key and keeps the rest', function () use ($proof, $timing): void {
    $ledger = Ledger::empty()
        ->withProof($proof('a', 'src/A.php'))
        ->withProof($proof('b', 'src/B.php'))
        ->withTiming($timing('src/A.php', 1.0))
        ->withPassed(Revision::ref('5eeca8f'))
        ->withoutProof(Digest::of('a'));

    expect($ledger->proofs())->toEqual(Proofs::of($proof('b', 'src/B.php')))
        ->and($ledger->timings())->toEqual(Timings::of($timing('src/A.php', 1.0)))
        ->and($ledger->lastPassed())->toEqual(Revision::ref('5eeca8f'));
});

it('takes what a shard measured, each unit keeping its newest timing', function () use ($proof, $timing): void {
    $ledger = Ledger::empty()
        ->withProof($proof('a', 'src/A.php'))
        ->withTiming($timing('src/A.php', 1.0, '2026-09-29T21:00:00Z'))
        ->withPassed(Revision::ref('5eeca8f'))
        ->withTimings(Timings::of($timing('src/A.php', 9.0), $timing('src/B.php', 2.0)));

    expect($ledger->timings())->toEqual(Timings::of($timing('src/A.php', 1.0, '2026-09-29T21:00:00Z'), $timing('src/B.php', 2.0)))
        ->and($ledger->proofs())->toEqual(Proofs::of($proof('a', 'src/A.php')))
        ->and($ledger->lastPassed())->toEqual(Revision::ref('5eeca8f'));
});

it('reads another scope\'s ledger beside its own, its own proofs and passing commit first', function () use ($proof, $timing): void {
    $ours = Ledger::empty()
        ->withProof($proof('a', 'src/Ours.php'))
        ->withTiming($timing('src/A.php', 1.0))
        ->withPassed(Revision::ref('5eeca8f'));
    $theirs = Ledger::empty()
        ->withProof($proof('a', 'src/Theirs.php'))
        ->withProof($proof('b', 'src/B.php'))
        ->withTiming($timing('src/A.php', 3.0, '2026-09-29T21:00:00Z'))
        ->withPassed(Revision::ref('206b4e0'));
    $read = $ours->and($theirs);

    expect($read->proofs())->toEqual(Proofs::of($proof('a', 'src/Ours.php'), $proof('b', 'src/B.php')))
        ->and($read->timings())->toEqual(Timings::of($timing('src/A.php', 3.0, '2026-09-29T21:00:00Z')))
        ->and($read->lastPassed())->toEqual(Revision::ref('5eeca8f'))
        ->and(Ledger::empty()->and($theirs)->lastPassed())->toEqual(CannotTell::because('No commit of this scope has passed yet.'));
});

it('keeps only the timings of units that still exist, and every proof', function () use ($proof, $timing): void {
    $ledger = Ledger::empty()
        ->withProof($proof('a', 'src/Gone.php'))
        ->withTiming($timing('src/Gone.php', 1.0))
        ->withTiming($timing('src/Here.php', 2.0))
        ->withPassed(Revision::ref('5eeca8f'))
        ->keepingTimingsOf(Paths::of(Path::of('src/Here.php')));

    expect($ledger->timings())->toEqual(Timings::of($timing('src/Here.php', 2.0)))
        ->and($ledger->proofs())->toEqual(Proofs::of($proof('a', 'src/Gone.php')))
        ->and($ledger->lastPassed())->toEqual(Revision::ref('5eeca8f'));
});

it('leaves the ledger it came from as it was', function () use ($proof, $timing): void {
    $ledger = Ledger::empty();
    $ledger->withProof($proof('a', 'src/A.php'));
    $ledger->withTiming($timing('src/A.php', 1.0));
    $ledger->withTimings(Timings::of($timing('src/A.php', 1.0)));
    $ledger->withPassed(Revision::ref('5eeca8f'));

    expect($ledger)->toEqual(Ledger::empty());
});

it('takes many proofs at once, keeping its own where both prove a key', function () use ($proof): void {
    $ledger = Ledger::empty()->withProof($proof(str_repeat('a', 64), 'src/Held.php'));

    $taken = $ledger->withProofs(Proofs::of(
        $proof(str_repeat('a', 64), 'src/Other.php'),
        $proof(str_repeat('b', 64), 'src/B.php'),
    ));

    expect($taken->proofs())->toEqual(Proofs::of(
        $proof(str_repeat('a', 64), 'src/Held.php'),
        $proof(str_repeat('b', 64), 'src/B.php'),
    ))
        ->and($ledger->proofs())->toHaveCount(1);
});
