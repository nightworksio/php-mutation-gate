<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Proof\Records;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Report\Explanation;
use NightWorksIO\MutationGate\Core\Report\Explanations;
use NightWorksIO\MutationGate\Core\Report\ExplanationText;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Tests\Support\Explained;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/** A mutant as the last run judged it, in this unit, with no reach and no history. */
function explainedIn(JudgedMutant|JudgedKill $judged, JudgedUnit|CannotTell $unit, Reasons $reach): string
{
    $sought = IdPrefix::of($judged->mutant()->id());

    return ExplanationText::of(Explanations::ofMutant(
        Explanation::judged($judged, KillMatrix::none(), $unit, $reach, Records::none($sought)),
    ));
}

it('says how the last run took each unit, with the run its result is from where a proof names one', function (
    Origin $origin,
    string $said,
): void {
    $unit = JudgedUnit::of(Unit::file(Path::of('src/Money.php')), $origin);
    $judged = JudgedMutant::of(Explained::mutant(MutantStatus::Survived), MutantJudgement::Survived);
    $from = $unit->withRun(Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')));

    expect(explainedIn($judged, $unit, Reasons::of()))->toContain(sprintf("\n    %s\n", $said))
        ->and(explainedIn($judged, $from, Reasons::of()))->toContain(sprintf("\n    %s Its result is from run github:7/1.\n", $said));
})->with([
    'run' => [Origin::Run, 'Unit: src/Money.php, run by the last run.'],
    'proved' => [Origin::Proved, 'Unit: src/Money.php, proved by the last run from a proof whose key still matches.'],
    'carried' => [Origin::Carried, 'Unit: src/Money.php, carried by the last run, whose change does not reach it.'],
]);

it('gives the reach only of a unit the last run ran', function (): void {
    $judged = JudgedMutant::of(Explained::mutant(MutantStatus::Survived), MutantJudgement::Survived);
    $reach = Reasons::of(Reason::that('src/Money.php changed.'));
    $carried = JudgedUnit::of(Unit::file(Path::of('src/Money.php')), Origin::Carried);

    expect(explainedIn($judged, JudgedUnit::of(Unit::file(Path::of('src/Money.php')), Origin::Run), $reach))
        ->toContain("\n    Unit: src/Money.php, run by the last run. Reach:\n        src/Money.php changed.\n")
        ->and(explainedIn($judged, $carried, $reach))->not->toContain('src/Money.php changed.');
});

it('gives the limit of a mutant that ran out of time or memory, with what its tests need where that was measured', function (
    MutantStatus $status,
    Seconds|MemoryCap $limit,
    Seconds|MemoryCap|Unmeasured $need,
    string $said,
): void {
    $mutant = Explained::mutant($status)->withLimit($limit);
    $judged = JudgedMutant::of(
        $need instanceof Unmeasured ? $mutant : $mutant->withUnmutatedNeed($need),
        MutantJudgement::TooSlowToJudge,
    );

    expect(explainedIn($judged, CannotTell::because('Not run.'), Reasons::of()))->toContain(sprintf("\n    %s\n", $said));
})->with([
    'timed out' => [MutantStatus::TimedOut, fn(): Seconds => Seconds::of(5.0), fn(): Seconds => Seconds::of(1.25), 'Limit: 5.00s; its judging tests took 1.25s unmutated under it'],
    'skipped' => [MutantStatus::Skipped, fn(): Seconds => Seconds::of(5.0), fn(): Unmeasured => Unmeasured::duration(), 'Limit: 5.00s'],
    'out of memory' => [MutantStatus::OutOfMemory, fn(): MemoryCap => MemoryCap::of(256, MemoryUnit::Megabytes), fn(): MemoryCap => MemoryCap::of(300, MemoryUnit::Megabytes), 'Limit: 256M; its judging tests take 300M on their own'],
]);

it('gives no limit to a mutant that did not run out of one, nor to one whose limit nobody recorded', function (): void {
    $survived = JudgedMutant::of(Explained::mutant(MutantStatus::Survived)->withLimit(Seconds::of(5.0)), MutantJudgement::Survived);
    $unlimited = JudgedMutant::of(Explained::mutant(MutantStatus::TimedOut), MutantJudgement::TooSlowToJudge);

    expect(explainedIn($survived, CannotTell::because('Not run.'), Reasons::of()))->not->toContain('Limit:')
        ->and(explainedIn($unlimited, CannotTell::because('Not run.'), Reasons::of()))->not->toContain('Limit:');
});

it('explains a kill a ledger proved, which keeps no diff, and says where no ledger holds a mutant', function (): void {
    $kill = JudgedKill::of(ProvedKill::of(Explained::id(), Path::of('src/Money.php'), Line::of(16), 'GreaterThan', TestIds::none()));

    expect(explainedIn($kill, CannotTell::because('No run has left a plan here.'), Reasons::of()))->toBe(sprintf(<<<'SAID'
        src/Money.php:16  GreaterThan  killed  %1$s
            A test fails with it in place.
            Covered by: no test
            Unit: unknown. No run has left a plan here.
            History: no ledger read holds it
            Reproduce: vendor/bin/mutation-gate reproduce %1$s
        SAID, Explained::id()->value()));
});

it('heads a cluster with its heading, hint and stub, then explains each member with the tests that judge it', function (): void {
    $explained = Explained::cluster();
    $cluster = $explained->cluster();
    $text = ExplanationText::of($explained);

    expect($cluster)->toBeInstanceOf(Cluster::class)
        ->and($cluster instanceof Cluster ? $text : '')->toStartWith($cluster instanceof Cluster ? sprintf(
            "src/Cart.php:7  3 survivors, one expression  %s\n    %s %s\n    Stub: vendor/bin/mutation-gate stub %s\n\nsrc/Cart.php:7  %s",
            $cluster->id()->value(),
            $cluster->kind()->text(),
            $cluster->representative()->hint()->text(),
            $cluster->id()->value(),
            $cluster->representative()->mutant()->mutator(),
        ) : 'a cluster')
        ->and(substr_count($text, "\n    Judged by: CartTest::fits, CartTest::saves\n"))->toBe(3)
        ->and(substr_count($text, "\n\nsrc/Cart.php:7  "))->toBe(3);
});

it('names the tests that judge a mutant only where they are not the tests that cover it', function (
    string $judging,
    string $outcome,
    string $said,
): void {
    $mutant = Explained::mutant(MutantStatus::Killed)->killedBy(TestIds::of(TestId::of('MoneyTest::adds')));
    $judged = JudgedMutant::of($mutant, MutantJudgement::Killed)->judgedBy(TestIds::of(TestId::of($judging)));
    $text = explainedIn($judged, CannotTell::because('Not run.'), Reasons::of());

    expect($text)->toContain(sprintf("\n    Covered by:\n        MoneyTest::adds  %s\n    %s", $outcome, $said))
        ->and(substr_count($text, 'Judged by:'))->toBe($said === 'Unit:' ? 0 : 1);
})->with([
    'the covering test' => ['MoneyTest::adds', 'killed', 'Unit:'],
    'another test' => ['MoneyTest::fits', 'not-run', "Judged by: MoneyTest::fits\n"],
]);
