<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Scopes;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A ledger with a proof of a unit under a key, at a base, a timing of it, and a passed commit. */
$ledger = static function (string $unit, string $key, string $base, string $passed): Ledger {
    $at = Moment::at('2026-09-30T10:00:00Z');
    $run = Run::of('local', $at, Digest::sha256Of($base));

    return Ledger::empty()
        ->withProof(Proof::of(Digest::of($key), Path::of($unit), Mutants::none(), $run))
        ->withTiming(Timing::of(Path::of($unit), Seconds::of(2.0), 'fake', $at))
        ->withPassed(Passed::of(Revision::ref($passed), 'check', 0));
};

/** The default branch's ledger and a pull request's, in a store. */
$store = static function () use ($ledger): ProofStoreFake {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $ledger('src/A.php', 'a', 'now', 'main-passed'));
    $store->write(Scope::pullRequest(7), $ledger('src/B.php', 'b', 'now', 'pr-passed'));

    return $store;
};

/** The ledgers a run on this ref reads from a store, where main is the default branch. */
function ledgersOn(RunOn $run, Writing $writing, ProofStoreFake $store): Ledgers
{
    $standing = Standing::of(
        new CiPlanFake(ShardId::of(1), $run),
        RepositoryFake::onMain(Revision::ref('head')),
        Absent::setting(),
    );

    return $standing instanceof Standing
        ? Ledgers::read($store, $standing, $writing)
        : throw new RuntimeException($standing->why());
}

$read = static fn(RunOn $run, Writing $writing = Writing::Auto): Ledgers => ledgersOn($run, $writing, $store());

it('reads and writes the default branch\'s ledger alone on the default branch', function () use ($read, $ledger): void {
    $ledgers = $read(RunOn::at(Scope::branch('main'), Scope::branch('main')));

    expect($ledgers->defaultBranch())->toEqual($ledger('src/A.php', 'a', 'now', 'main-passed'))
        ->and($ledgers->own())->toEqual(Ledger::empty())
        ->and($ledgers->written())->toEqual($ledger('src/A.php', 'a', 'now', 'main-passed'))
        ->and($ledgers->access()->reads())->toEqual(Scopes::of(Scope::branch('main')))
        ->and($ledgers->access()->writes())->toEqual(Scope::branch('main'))
        ->and($ledgers->lastPassed())->toEqual(Passed::of(Revision::ref('main-passed'), 'check', 0));
});

it('reads its own scope beside the default branch\'s, and writes its own', function () use ($read, $ledger): void {
    $ledgers = $read(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $timings = $ledgers->timings();

    expect($ledgers->defaultBranch())->toEqual($ledger('src/A.php', 'a', 'now', 'main-passed'))
        ->and($ledgers->own())->toEqual($ledger('src/B.php', 'b', 'now', 'pr-passed'))
        ->and($ledgers->written())->toEqual($ledger('src/B.php', 'b', 'now', 'pr-passed'))
        ->and($ledgers->access()->writes())->toEqual(Scope::pullRequest(7))
        ->and($timings->secondsFor(Path::of('src/A.php')))->toEqual(Seconds::of(2.0))
        ->and($timings->secondsFor(Path::of('src/B.php')))->toEqual(Seconds::of(2.0))
        ->and($timings)->toHaveCount(2)
        ->and($ledgers->lastPassed())->toEqual(Passed::of(Revision::ref('pr-passed'), 'check', 0));
});

it('writes nothing where proofs.write says never', function () use ($read): void {
    expect($read(RunOn::at(Scope::pullRequest(7), Scope::branch('main')), Writing::Never)->access()->writes())
        ->toBeInstanceOf(ReadsOnly::class);
});

it('reads the default branch\'s ledger alone when detached, with nothing passed of its own', function () use (
    $read,
    $ledger,
): void {
    $ledgers = $read(RunOn::detached(Scope::branch('main')));

    expect($ledgers->defaultBranch())->toEqual($ledger('src/A.php', 'a', 'now', 'main-passed'))
        ->and($ledgers->own())->toEqual(Ledger::empty())
        ->and($ledgers->written())->toEqual(Ledger::empty())
        ->and($ledgers->lastPassed())->toEqual(CannotTell::because(
            'The run has no ref of its own, so no commit of its own has passed.',
        ));
});

it('takes a proof whose key still matches from either ledger, and runs the rest', function () use ($read): void {
    $units = Units::of(
        Unit::file(Path::of('src/A.php')),
        Unit::file(Path::of('src/B.php')),
        Unit::file(Path::of('src/C.php')),
    );
    $keys = Keys::none()
        ->with(Path::of('src/A.php'), Digest::of('a'))
        ->with(Path::of('src/B.php'), Digest::of('b'))
        ->with(Path::of('src/C.php'), Digest::of('c'));
    $proving = $read(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->proving($units, $keys, Digest::sha256Of('now'));

    expect($proving->proved())->toHaveCount(2)
        ->and($proving->toRun())->toEqual(Units::of(Unit::file(Path::of('src/C.php'))))
        ->and($proving->ownScopeProofs())->toBe(1);
});

it('looks for no proof in a ledger that holds none at the base the keys are built on', function () use (
    $store,
    $ledger,
): void {
    $moved = $store();
    $moved->write(Scope::pullRequest(7), $ledger('src/B.php', 'b', 'before', 'pr-passed'));
    $ledgers = ledgersOn(RunOn::at(Scope::pullRequest(7), Scope::branch('main')), Writing::Auto, $moved);
    $units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')));
    $keys = Keys::none()->with(Path::of('src/A.php'), Digest::of('a'))->with(Path::of('src/B.php'), Digest::of('b'));
    $proving = $ledgers->proving($units, $keys, Digest::sha256Of('now'));
    $atBefore = $ledgers->proving($units, $keys, Digest::sha256Of('before'));

    expect($proving->toRun())->toEqual(Units::of(Unit::file(Path::of('src/B.php'))))
        ->and($proving->ownScopeProofs())->toBe(0)
        ->and($atBefore->toRun())->toEqual(Units::of(Unit::file(Path::of('src/A.php'))))
        ->and($atBefore->ownScopeProofs())->toBe(1);
});

it('counts no timing twice', function () use ($read): void {
    expect($read(RunOn::at(Scope::branch('main'), Scope::branch('main')))->timings())->toEqual(Timings::of(
        Timing::of(Path::of('src/A.php'), Seconds::of(2.0), 'fake', Moment::at('2026-09-30T10:00:00Z')),
    ));
});
