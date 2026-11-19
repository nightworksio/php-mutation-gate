<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\JobsLearned;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\MeasuredHolds;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\BriefingRecord;
use NightWorksIO\MutationGate\Core\Plan\InputsRead;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Plan\PlanLearned;
use NightWorksIO\MutationGate\Core\Plan\StandsBecause;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\JudgingRun;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Moment;

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

it('tells every shard the multiple and the pace the plan learned, which its file holds, and neither by default', function (PlanLearned $kills, array $written): void {
    $learning = Briefing::standard()->learning($kills)->weighing(NotGiven::value())->reading(InputsRead::none()->onOwnScopeCoverage());

    expect(Briefing::standard()->learned())->toEqual(PlanLearned::none())
        ->and($learning->learned())->toEqual($kills)
        ->and(BriefingRecord::of($learning))->toMatchArray($written)
        ->and(BriefingRecord::of(Briefing::standard()))->not->toHaveKeys(['learnedMultiple', 'learnedPace', 'learnedSetup', 'learnedWindDown', 'learnedPassTwo'])
        ->and(BriefingRecord::read(Node::decode((string) json_encode(BriefingRecord::of($learning)))))
        ->toEqual($learning);
})->with([
    'a multiple' => [fn(): PlanLearned => PlanLearned::none()->allowing(Multiple::of(4.5)), ['learnedMultiple' => 4.5]],
    'a pace' => [fn(): PlanLearned => PlanLearned::none()->pacing(Multiple::of(0.25)), ['learnedPace' => 0.25]],
    'both' => [
        fn(): PlanLearned => PlanLearned::none()->allowing(Multiple::of(4.5))->pacing(Multiple::of(0.25)),
        ['learnedMultiple' => 4.5, 'learnedPace' => 0.25],
    ],
    'what jobs take' => [
        fn(): PlanLearned => PlanLearned::none()->timing(JobsLearned::learned(Seconds::of(52.5), Seconds::of(9.0), 0.7)),
        ['learnedSetup' => 52.5, 'learnedWindDown' => 9.0, 'learnedPassTwo' => 0.7],
    ],
    'a wind-down alone' => [
        fn(): PlanLearned => PlanLearned::none()->timing(JobsLearned::learned(NotGiven::value(), Seconds::of(9.0))),
        ['learnedWindDown' => 9.0],
    ],
    'jobs that stop at their target' => [
        fn(): PlanLearned => PlanLearned::none()->tailing(JobTail::of(Seconds::of(21600.0), 19)),
        ['tailLimit' => 21600.0, 'tailPool' => 19],
    ],
    'jobs that run their own tail' => [
        fn(): PlanLearned => PlanLearned::none()->tailing(JobTail::of(Seconds::of(3600.0), 4)->running()),
        ['tailLimit' => 3600.0, 'tailPool' => 4, 'ownTail' => true],
    ],
]);

it('reads a plan file that names no tail, as one written before it, as jobs that stop at their target', function (): void {
    expect(BriefingRecord::read(Node::decode('{"ownTail": true}'))->learned()->tail())->toEqual(JobTail::unknown())
        ->and(BriefingRecord::of(Briefing::standard()))->not->toHaveKeys(['tailLimit', 'tailPool', 'ownTail']);
});

it('waits for a fresh map only once told to, keeping the rest of what it tells', function (): void {
    $peak = MemoryCap::of(300, MemoryUnit::Megabytes);
    $planned = Briefing::standard()->weighing($peak)->recording(MatrixKind::Full)->reading(InputsRead::none()->onOwnScopeCoverage())->plannedEarly();
    $waiting = $planned->waitingForAMap();

    expect(Briefing::standard()->waits())->toBeFalse()
        ->and($planned->waits())->toBeFalse()
        ->and($waiting->waits())->toBeTrue()
        ->and($waiting->isEarly())->toBeTrue()
        ->and($waiting->peak())->toBe($peak)
        ->and($waiting->profile()->matrix())->toBe(MatrixKind::Full)
        ->and($waiting->inputs()->isOnOwnScopeCoverage())->toBeTrue();
});

it('tells every shard what the plan measured of the held units, which its file holds, and nothing by default', function (): void {
    $measured = MeasuredHolds::none()->with(Unit::file(Path::of('src/A.php')), Covered::by(Unit::file(Path::of('src/A.php')), TestIds::of(TestId::of('ATest::runs'))));
    $briefing = Briefing::standard()->measuring($measured)->reading(InputsRead::none()->onOwnScopeCoverage())->plannedEarly()->waitingForAMap();

    expect(Briefing::standard()->held())->toEqual(NotGiven::value())
        ->and($briefing->held())->toBe($measured)
        ->and(BriefingRecord::of($briefing))->toMatchArray(['held' => ['src/A.php' => ['tests' => ['ATest::runs']]]])
        ->and(BriefingRecord::of(Briefing::standard()))->not->toHaveKey('held')
        ->and(BriefingRecord::read(Node::decode((string) json_encode(BriefingRecord::of($briefing)))))->toEqual($briefing)
        ->and($briefing->learning(PlanLearned::none())->unchangedSince(
            Unchanged::standing(StandsBecause::NothingReached, Passed::of(Revision::ref('5eeca8f'), 'mutation-gate', 0), Instant::at(new DateTimeImmutable('2026-11-16T12:00:00Z'))),
        )->ofKind(RunProfile::standard())->recording(MatrixKind::Full)->weighing(NotGiven::value())->held())->toBe($measured);
});

it('takes what the plan it stands as measured of the held units, and keeps its own where that one measured none', function (): void {
    $measured = MeasuredHolds::none()->with(Unit::file(Path::of('src/A.php')), Covered::by(Unit::file(Path::of('src/A.php')), TestIds::of(TestId::of('ATest::runs'))));
    $other = MeasuredHolds::none()->with(Unit::file(Path::of('src/B.php')), Covered::by(Unit::file(Path::of('src/B.php')), TestIds::none()));

    expect(Briefing::standard()->standingAs(Briefing::standard()->measuring($measured))->held())->toBe($measured)
        ->and(Briefing::standard()->measuring($other)->standingAs(Briefing::standard())->held())->toBe($other)
        ->and(Briefing::standard()->standingAs(Briefing::standard())->held())->toEqual(NotGiven::value());
});

it('tells the verdict the digest of every input the run reads, which its file holds and the plan it stands as keeps, and none by default', function (): void {
    $inputs = Digest::sha256Of('every input');
    $reading = Briefing::standard()->weighing(MemoryCap::of(300, MemoryUnit::Megabytes))->reading(InputsRead::none()->digested($inputs));
    $both = $reading->reading($reading->inputs()->onOwnScopeCoverage());
    $read = static fn(Briefing $briefing): Briefing => BriefingRecord::read(Node::decode((string) json_encode(BriefingRecord::of($briefing))));

    expect(Briefing::standard()->inputs())->toEqual(InputsRead::none())
        ->and([InputsRead::none()->digest(), InputsRead::none()->isOnOwnScopeCoverage()])->toEqual([NotGiven::value(), false])
        ->and($reading->inputs()->digest())->toBe($inputs)
        ->and($reading->inputs()->isOnOwnScopeCoverage())->toBeFalse()
        ->and([$both->inputs()->digest(), $both->inputs()->isOnOwnScopeCoverage()])->toEqual([$inputs, true])
        ->and($reading->recording(MatrixKind::Full)->plannedEarly()->waitingForAMap()->inputs())->toBe($reading->inputs())
        ->and(BriefingRecord::of($reading))->toHaveKey('inputs', $inputs->value())
        ->and(BriefingRecord::of($both))->toMatchArray(['inputs' => $inputs->value(), 'ownScopeCoverage' => true])
        ->and(BriefingRecord::of(Briefing::standard()))->not->toHaveKeys(['inputs', 'ownScopeCoverage'])
        ->and($read($reading))->toEqual($reading)
        ->and($read($both))->toEqual($both)
        ->and(static fn(): Briefing => BriefingRecord::read(Node::decode('{"inputs": "abc"}')))->toThrow(NotInShape::class);
});

it('writes a verdict reused under identicalTo, with where its pass was read, and reads it back', function (): void {
    $at = Moment::at('2026-11-19T12:00:00Z');
    $passed = Passed::of(Revision::ref(str_repeat('a', 40)), 'mutation / verdict', 0)
        ->passedAt($at)
        ->judgedIn(JudgingRun::of(Digest::sha256Of('every input'), 'https://github.com/octo/gate/actions/runs/9', whole: true));
    $unchanged = Unchanged::standing(StandsBecause::IdenticalInputs, $passed, $at, 'pull request #7');
    $written = BriefingRecord::of(Briefing::standard()->unchangedSince($unchanged));
    $read = BriefingRecord::read(Node::decode((string) json_encode($written)))->unchanged();

    expect($written['identicalTo'] ?? [])->toMatchArray(['from' => 'pull request #7', 'run' => 'https://github.com/octo/gate/actions/runs/9', 'whole' => true])
        ->and($read)->toEqual($unchanged);
});
