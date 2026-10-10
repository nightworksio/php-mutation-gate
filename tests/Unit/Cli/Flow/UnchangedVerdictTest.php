<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\UnchangedVerdict;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Change\Tree;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

const UNCHANGED_VERDICT_BASE = '5eeca8f0a1b2c3d4e5f60718293a4b5c6d7e8f90';

afterEach(function (): void {
    Scratch::sweep();
});

$unchanged = static fn(): Unchanged => Unchanged::since(
    Passed::of(Revision::ref(UNCHANGED_VERDICT_BASE), 'mutation / verdict', 2)
        ->passedAt(Instant::at(new DateTimeImmutable('2026-09-20T10:00:00Z'))),
    Instant::at(new DateTimeImmutable('2026-09-20T10:00:00Z')),
);

$plan = static fn(): Plan => Plan::of(Revision::ref(Flows::HEAD), Digest::sha256Of(''), Keys::none(), Shards::none())
    ->on(RunOn::at(Scope::branch('main'), Scope::branch('main')))
    ->briefed(Briefing::standard()->unchangedSince($unchanged()));

$store = static function (): ProofStoreFake {
    $store = new ProofStoreFake();
    $judged = Tree::parse('4b825dc642cb6eb9a060e54bf8d69288fbee4904');
    $lastRun = $judged instanceof Tree
        ? ScopeRuns::none()->lastRunAt(LastRun::of(JudgedCommit::of(Revision::ref(UNCHANGED_VERDICT_BASE), $judged), 'mutation / verdict', RunProfile::standard()))
        : ScopeRuns::none();
    $store->write(Scope::branch('main'), Ledger::empty()->withRuns($lastRun));

    return $store;
};

it('passes, saying why, reports it, and records the commit the plan was made on as passed now, using what the commit it stands on used', function () use ($unchanged, $plan, $store): void {
    $proofs = $store();
    $reporter = new ReporterFake();
    $judged = new UnchangedVerdict(
        Flows::adapters(Flows::project(), [], $proofs),
        JudgingRuns::settings(),
        Flows::setup(),
        JudgingRuns::reporting($reporter),
    )->of($plan(), $unchanged());
    $runs = LedgerRead::ledger($proofs->read(Scope::branch('main')))->runs();

    expect($judged instanceof Judged ? $judged->verdict->judgement() : $judged)->toBe(Judgement::Passed)
        ->and($judged instanceof Judged ? array_map(static fn(Reason $reason): string => $reason->text(), [...$judged->verdict->reach()]) : $judged)
        ->toBe([$unchanged()->reason()->text()])
        ->and($judged instanceof Judged ? count($judged->verdict->trees()) : $judged)->toBe(0)
        ->and($judged instanceof Judged ? $judged->said : $judged)->toBe(['Wrote memory:refs/heads/main.', 'Wrote memory.'])
        ->and($reporter->reported)->toHaveCount(1)
        ->and($runs->passed())->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 2)->passedAt(Instant::at(new DateTimeImmutable(Configs::NOW))))
        ->and($runs->lastRun())->toBeInstanceOf(CannotTell::class);
});

it('cannot judge where an ignore that applied when the commit passed has expired since', function () use ($unchanged, $plan, $store): void {
    $judged = new UnchangedVerdict(
        Flows::adapters(Flows::project(), [], $store()),
        JudgingRuns::settings(Ignore::mutant('49e02fb39669', 'The bound is never reached', '2026-09-25')),
        Flows::setup(),
        JudgingRuns::reporting(new ReporterFake()),
    )->of($plan(), $unchanged());

    expect($judged)->toEqual(CannotJudge::because(sprintf(
        'The ignore of 49e02fb39669 has expired since %s passed, so its verdict no longer stands. Plan the run again.',
        UNCHANGED_VERDICT_BASE,
    )));
});

it('passes under an ignore that had expired before the commit passed, or lasts past now', function (string $until) use ($unchanged, $plan, $store): void {
    $judged = new UnchangedVerdict(
        Flows::adapters(Flows::project(), [], $store()),
        JudgingRuns::settings(Ignore::mutant('49e02fb39669', 'The bound is never reached', $until)),
        Flows::setup(),
        JudgingRuns::reporting(new ReporterFake()),
    )->of($plan(), $unchanged());

    expect($judged instanceof Judged ? $judged->verdict->judgement() : $judged)->toBe(Judgement::Passed);
})->with([
    'expired before' => ['2026-09-10'],
    'lasting past now' => ['2026-12-31'],
]);

it('passes and says why it records nothing where the run may not write its scope', function () use ($unchanged, $plan): void {
    $proofs = new ProofStoreFake();
    $detached = $plan()->on(RunOn::detached(Scope::branch('main')));
    $judged = new UnchangedVerdict(
        Flows::adapters(Flows::project(), [], $proofs),
        JudgingRuns::settings(),
        Flows::setup(),
        JudgingRuns::reporting(new ReporterFake()),
    )->of($detached, $unchanged());

    expect($judged instanceof Judged ? $judged->verdict->judgement() : $judged)->toBe(Judgement::Passed)
        ->and($judged instanceof Judged ? $judged->said[0] : $judged)->not->toStartWith('Wrote')
        ->and(LedgerRead::ledger($proofs->read(Scope::branch('main'))))->toEqual(Ledger::empty());
});
