<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\NeverProved;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Scopes;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
function ledgersOn(RunOn $run, Writing $writing, ProofStore $store): Ledgers
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

it('says why for each ledger the store could not read, and judges without it', function (): void {
    $unread = Unreadable::because(UnreadReason::TimedOut, 'https://ledgers.example.com', 'no answer came in time');
    $ledgers = ledgersOn(
        RunOn::at(Scope::pullRequest(7), Scope::branch('main')),
        Writing::Auto,
        ProofStoreFake::unreadable($unread),
    );

    expect($ledgers->unread())->toEqual(Warnings::of(Warning::that($unread->why()), Warning::that($unread->why())))
        ->and($ledgers->defaultBranch())->toEqual(Ledger::empty())
        ->and($ledgers->own())->toEqual(Ledger::empty());
});

it('says nothing was unread where the store read every ledger', function () use ($read): void {
    expect($read(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))->unread())->toEqual(Warnings::none());
});

it('reads the default branch\'s ledger alone through a store that reads no other, and says why it could not', function (): void {
    $sent = [];
    $client = new MockHttpClient(static function (string $method, string $url) use (&$sent): MockResponse {
        $sent[] = $url;

        return new MockResponse('not a ledger');
    });
    $store = PublicLedger::at($client, 'https://ledgers.example.com', 'mutation-gate')->onlyReading(Scope::branch('main'));
    $ledgers = ledgersOn(RunOn::at(Scope::pullRequest(7), Scope::branch('main')), Writing::Auto, $store);

    expect($sent)->toBe(['https://ledgers.example.com/mutation-gate/refs/heads/main/ledger.json.gz'])
        ->and($ledgers->unread())->toHaveCount(1)
        ->and([...$ledgers->unread()][0]->text())->toBe(
            'The ledger is unreadable from https://ledgers.example.com/mutation-gate/refs/heads/main/ledger.json.gz: '
            . 'The ledger is not a whole gzip stream. The run judges without it.',
        );
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

it('reads the kill history of every ledger, its own scope\'s where both know a mutant', function () use (
    $ledger,
): void {
    $mutant = MutantId::hash(Path::of('src/A.php'), 'Plus', '@@ @@', 0);
    $add = Enclosing::named(Path::of('src/A.php'), 'add');
    $killedBy = static fn(string $test): Ranking => Ranking::of(Kills::of(TestId::of($test), 1));
    $main = KillHistory::none()->withMutant($mutant, $killedBy('main'))->withFunction($add, $killedBy('main'));
    $own = KillHistory::none()->withMutant($mutant, $killedBy('own'));
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $ledger('src/A.php', 'a', 'now', 'main-passed')->withKillers($main));
    $store->write(Scope::pullRequest(7), $ledger('src/B.php', 'b', 'now', 'pr-passed')->withKillers($own));
    $killers = ledgersOn(RunOn::at(Scope::pullRequest(7), Scope::branch('main')), Writing::Auto, $store)->killers();

    expect($killers)->toEqual($own->and($main))
        ->and($killers->likelyKillers($mutant, $add))->toEqual(TestIds::of(TestId::of('own')))
        ->and([...$killers->functions()])->toHaveCount(1);
});

it('gives each unit its newest result in any ledger it read, its own scope\'s first of two as new', function (): void {
    $store = new ProofStoreFake();
    $proof = static fn(string $key, string $at): Proof => Proof::of(
        Digest::of($key),
        Path::of('src/A.php'),
        Mutants::none(),
        Run::of('local', Moment::at($at), Digest::sha256Of('now')),
    );
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($proof('main', '2026-09-30T10:00:00Z')));
    $store->write(Scope::pullRequest(7), Ledger::empty()->withProof($proof('own', '2026-09-30T10:00:00Z')));
    $newer = new ProofStoreFake();
    $newer->write(Scope::branch('main'), Ledger::empty()->withProof($proof('main', '2026-09-30T11:00:00Z')));
    $newer->write(Scope::pullRequest(7), Ledger::empty()->withProof($proof('own', '2026-09-30T10:00:00Z')));
    $run = RunOn::at(Scope::pullRequest(7), Scope::branch('main'));
    $newest = static fn(ProofStoreFake $in): Proof|NeverProved => ledgersOn($run, Writing::Auto, $in)->newest()->of(Path::of('src/A.php'));

    expect($newest($store))->toEqual($proof('own', '2026-09-30T10:00:00Z'))
        ->and($newest($newer))->toEqual($proof('main', '2026-09-30T11:00:00Z'));
});
