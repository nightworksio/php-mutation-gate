<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Hold\MeasuredHolds;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\BriefingRecord;
use NightWorksIO\MutationGate\Core\Plan\KillsLearned;
use NightWorksIO\MutationGate\Core\Plan\StandsBecause;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;

it('measures no peak and records first killers, by default', function (): void {
    expect(Briefing::standard()->peak())->toEqual(NotGiven::value())
        ->and(Briefing::standard()->profile()->matrix())->toBe(MatrixKind::FirstKiller);
});

it('takes each of what it tells on its own, leaving the other as it was', function (): void {
    $peak = MemoryCap::of(300, MemoryUnit::Megabytes);
    $weighed = Briefing::standard()->weighing($peak);
    $recording = Briefing::standard()->recording(MatrixKind::Full);

    expect($weighed->peak())->toBe($peak)
        ->and($weighed->profile()->matrix())->toBe(MatrixKind::FirstKiller)
        ->and($recording->profile()->matrix())->toBe(MatrixKind::Full)
        ->and($recording->peak())->toEqual(NotGiven::value())
        ->and($weighed->recording(MatrixKind::Full)->peak())->toBe($peak);
});

it('has every test judge the mutants by default, and one suite\'s tests alone where it is told', function (): void {
    $suite = Briefing::standard()->ofKind(RunProfile::standard()->recording(MatrixKind::Full)->inSuite(SuiteName::of('unit')));

    expect(Briefing::standard()->profile()->suite())->toEqual(NotGiven::value())
        ->and($suite->profile()->suite())->toEqual(SuiteName::of('unit'))
        ->and($suite->profile()->matrix())->toBe(MatrixKind::Full)
        ->and($suite->profile()->isSecurityOnly())->toBeFalse();
});

it('tells every shard the multiple and the pace the plan learned, which its file holds, and neither by default', function (KillsLearned $kills, array $written): void {
    $learning = Briefing::standard()->learning($kills)->weighing(NotGiven::value())->onOwnScopeCoverage();

    expect(Briefing::standard()->learned())->toEqual(KillsLearned::none())
        ->and($learning->learned())->toEqual($kills)
        ->and(BriefingRecord::of($learning))->toMatchArray($written)
        ->and(BriefingRecord::of(Briefing::standard()))->not->toHaveKeys(['learnedMultiple', 'learnedPace'])
        ->and(BriefingRecord::read(Node::decode((string) json_encode(BriefingRecord::of($learning)))))
        ->toEqual($learning);
})->with([
    'a multiple' => [fn(): KillsLearned => KillsLearned::none()->allowing(Multiple::of(4.5)), ['learnedMultiple' => 4.5]],
    'a pace' => [fn(): KillsLearned => KillsLearned::none()->pacing(Multiple::of(0.25)), ['learnedPace' => 0.25]],
    'both' => [
        fn(): KillsLearned => KillsLearned::none()->allowing(Multiple::of(4.5))->pacing(Multiple::of(0.25)),
        ['learnedMultiple' => 4.5, 'learnedPace' => 0.25],
    ],
]);

it('waits for a fresh map only once told to, keeping the rest of what it tells', function (): void {
    $peak = MemoryCap::of(300, MemoryUnit::Megabytes);
    $planned = Briefing::standard()->weighing($peak)->recording(MatrixKind::Full)->onOwnScopeCoverage()->plannedEarly();
    $waiting = $planned->waitingForAMap();

    expect(Briefing::standard()->waits())->toBeFalse()
        ->and($planned->waits())->toBeFalse()
        ->and($waiting->waits())->toBeTrue()
        ->and($waiting->isEarly())->toBeTrue()
        ->and($waiting->peak())->toBe($peak)
        ->and($waiting->profile()->matrix())->toBe(MatrixKind::Full)
        ->and($waiting->isOnOwnScopeCoverage())->toBeTrue();
});

it('tells every shard what the plan measured of the held units, which its file holds, and nothing by default', function (): void {
    $measured = MeasuredHolds::of(['src/A.php' => TestIds::of(TestId::of('ATest::runs'))]);
    $briefing = Briefing::standard()->measuring($measured)->onOwnScopeCoverage()->plannedEarly()->waitingForAMap();

    expect(Briefing::standard()->held())->toEqual(NotGiven::value())
        ->and($briefing->held())->toBe($measured)
        ->and(BriefingRecord::of($briefing))->toMatchArray(['held' => ['src/A.php' => ['tests' => ['ATest::runs']]]])
        ->and(BriefingRecord::of(Briefing::standard()))->not->toHaveKey('held')
        ->and(BriefingRecord::read(Node::decode((string) json_encode(BriefingRecord::of($briefing)))))->toEqual($briefing)
        ->and($briefing->learning(KillsLearned::none())->unchangedSince(
            Unchanged::standing(StandsBecause::NothingReached, Passed::of(Revision::ref('5eeca8f'), 'mutation-gate', 0), Instant::at(new DateTimeImmutable('2026-11-16T12:00:00Z'))),
        )->ofKind(RunProfile::standard())->recording(MatrixKind::Full)->weighing(NotGiven::value())->held())->toBe($measured);
});

it('takes what the plan it stands as measured of the held units, and keeps its own where that one measured none', function (): void {
    $measured = MeasuredHolds::of(['src/A.php' => TestIds::of(TestId::of('ATest::runs'))]);
    $other = MeasuredHolds::of(['src/B.php' => TestIds::none()]);

    expect(Briefing::standard()->standingAs(Briefing::standard()->measuring($measured))->held())->toBe($measured)
        ->and(Briefing::standard()->measuring($other)->standingAs(Briefing::standard())->held())->toBe($other)
        ->and(Briefing::standard()->standingAs(Briefing::standard())->held())->toEqual(NotGiven::value());
});
