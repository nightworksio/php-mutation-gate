<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\Unsampled;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unraised;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

// The money file sampled, the held path known exactly, and a file of 40
// mutants left out: src scores about 48.88%, between 21.04% and 76.74%.
$sampled = static fn(): Plan => Planned::twoShards()->considering(Considered::everything()->sampling(
    Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0)->holding(
        Units::of(Planned::money()),
        Unsampled::none()->with(Candidate::of(Unit::file(Path::of('src/Left.php')), 40, NotGiven::value())),
    ),
));

$judged = static fn(Plan $plan, Floor $floor, ProofStoreFake $store, object ...$ports): Judged|CannotJudge => (static function () use ($plan, $floor, $store, $ports): Judged|CannotJudge {
    $judged = JudgingRuns::judged(
        $plan,
        Flows::adapters(Flows::project(), [], JudgingRuns::tree($floor), $store, ...$ports),
        JudgingRuns::settings(),
        JudgingRuns::reporting(new ReporterFake()),
    );

    return $judged instanceof Judged || $judged instanceof CannotJudge ? $judged : CannotJudge::because('The config is invalid.');
})();

it('passes a tree whose interval lies above its floor, and fails one whose interval lies below it', function (Floor $floor, Judgement $judgement, ExitCode $exit) use ($sampled, $judged): void {
    $judgement_ = $judged($sampled(), $floor, new ProofStoreFake());

    expect($judgement_ instanceof Judged ? [$judgement_->verdict->judgement(), $judgement_->exitCode()] : $judgement_)
        ->toBe([$judgement, $exit]);
})->with([
    'above' => [fn(): Floor => Floor::of(20), Judgement::Passed, fn(): ExitCode => ExitCode::Passed],
    'below' => [fn(): Floor => Floor::of(80), Judgement::Failed, fn(): ExitCode => ExitCode::Failed],
]);

it('cannot judge a tree whose interval holds its floor, and says so with the interval and how to decide it', function () use ($sampled, $judged): void {
    $judgement = $judged($sampled(), Floor::of(50), new ProofStoreFake());
    $verdict = $judgement instanceof Judged ? $judgement->verdict : null;

    expect($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::CannotJudge)
        ->and($verdict?->judgement())->toBe(Judgement::CannotJudge)
        ->and(array_map(static fn(CannotJudge $why): string => $why->why(), $verdict instanceof Verdict ? [...$verdict->obstacles()] : []))->toBe([
            'src scores about 48.88% (95%: 21.04%–76.74%; 1 of 3 units sampled, 1 exact), and its floor of 50.00% lies within it: a smaller sampling.margin, or a full run, decides it.',
        ]);
});

it('records the proofs of the units it ran, but no passing commit and no run that judged every unit', function () use ($sampled, $judged): void {
    $store = new ProofStoreFake();
    $judgement = $judged($sampled(), Floor::of(20), $store);
    $ledger = LedgerRead::ledger($store->read(Scope::branch('main')));
    $whole = new ProofStoreFake();
    $judged(Planned::twoShards(), Floor::of(20), $whole);

    expect($judgement instanceof Judged ? $judgement->verdict->judgement() : $judgement)->toBe(Judgement::Passed)
        ->and(count($ledger->proofs()))->toBe(2)
        ->and($ledger->runs()->passed())->toBeInstanceOf(CannotTell::class)
        ->and($ledger->runs()->lastRun())->toBeInstanceOf(CannotTell::class)
        ->and(LedgerRead::ledger($whole->read(Scope::branch('main')))->runs()->passed())->toBeInstanceOf(Passed::class)
        ->and(LedgerRead::ledger($whole->read(Scope::branch('main')))->runs()->lastRun())->toBeInstanceOf(LastRun::class);
});

it('raises no floor, and judges no security set, from a sample', function () use ($sampled, $judged): void {
    $judgement = $judged($sampled(), Floor::of(20), new ProofStoreFake(), NamedMutators::of('Plus'));
    $verdict = $judgement instanceof Judged ? $judgement->verdict : null;
    $tree = $verdict instanceof Verdict ? [...$verdict->trees()][0] : null;
    $security = $verdict instanceof Verdict ? [...$verdict->sets()->security()] : [];

    expect($tree?->raised())->toEqual(Unraised::floor())
        ->and(count($security))->toBe(1)
        ->and($security[0]->floor())->toEqual(Exempt::because('--sample estimates the trees and judges no security set'))
        ->and($security[0]->raised())->toEqual(Unraised::floor());
});
