<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TimeoutTriage;

/** A mutant of `src/Loop.php` with this status, allowed this limit, whose judging tests take this long. */
function triagedMutant(MutantStatus $status, float $limit, float $tests): Mutant
{
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Loop.php'), 'LessThan', '3', 0),
        '1',
        Location::of(Path::of('src/Loop.php'), Line::of(3), Line::of(3)),
        Mutation::of('LessThan', MutatorFamily::Boundary, ''),
        $status,
        Unmeasured::duration(),
    );
    $limited = $limit > 0 ? $mutant->withLimit(Seconds::of($limit)) : $mutant;

    return $tests > 0 ? $limited->withUnmutatedNeed(Seconds::of($tests)) : $limited;
}

it('kills a timeout whose judging tests take under half its limit, and no other', function (
    Mutant $mutant,
    MutantJudgement $judged,
): void {
    expect(TimeoutTriage::under(TimeoutMode::Confirm)->judged($mutant))->toBe($judged);
})->with([
    'well under half' => [triagedMutant(MutantStatus::TimedOut, 10.0, 1.0), MutantJudgement::KilledByTimeout],
    'just under half' => [triagedMutant(MutantStatus::TimedOut, 10.0, 4.99), MutantJudgement::KilledByTimeout],
    'half' => [triagedMutant(MutantStatus::TimedOut, 10.0, 5.0), MutantJudgement::TooSlowToJudge],
    'more than half' => [triagedMutant(MutantStatus::TimedOut, 10.0, 8.0), MutantJudgement::TooSlowToJudge],
    'no limit known' => [triagedMutant(MutantStatus::TimedOut, 0.0, 1.0), MutantJudgement::TooSlowToJudge],
    'no test time known' => [triagedMutant(MutantStatus::TimedOut, 10.0, 0.0), MutantJudgement::TooSlowToJudge],
    'a skipped mutant' => [triagedMutant(MutantStatus::Skipped, 10.0, 1.0), MutantJudgement::TooSlowToJudge],
    'a killed mutant' => [triagedMutant(MutantStatus::Killed, 10.0, 1.0), MutantJudgement::Killed],
]);

it('judges every timeout too slow to judge under timeouts.mode unjudged', function (): void {
    expect(TimeoutTriage::under(TimeoutMode::Unjudged)->judged(triagedMutant(MutantStatus::TimedOut, 10.0, 1.0)))
        ->toBe(MutantJudgement::TooSlowToJudge)
        ->and(TimeoutTriage::under(TimeoutMode::Unjudged)->judged(triagedMutant(MutantStatus::Survived, 10.0, 1.0)))
        ->toBe(MutantJudgement::Survived);
});

it('times each mutant whose time ran out by the tests covering its line, where the map measured them all', function (
    CoverageMap $map,
    string $time,
): void {
    $timed = [...TimeoutTriage::timed(Mutants::of(
        triagedMutant(MutantStatus::TimedOut, 10.0, 0.0),
        triagedMutant(MutantStatus::Survived, 0.0, 0.0),
    ), $map)];

    expect(array_map(static fn(Mutant $mutant): string => match (true) {
        $mutant->unmutatedNeed() instanceof Seconds => sprintf('%.1f', $mutant->unmutatedNeed()->seconds()),
        default => 'unmeasured',
    }, $timed))->toBe([$time, 'unmeasured']);
})->with([
    'two measured tests' => [
        CoverageMap::empty()
            ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::a'))
            ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::b'))
            ->timed(TestId::of('LoopTest::a'), Seconds::of(0.5))
            ->timed(TestId::of('LoopTest::b'), Seconds::of(1.0)),
        '1.5',
    ],
    'a test not measured' => [
        CoverageMap::empty()
            ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::a'))
            ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::b'))
            ->timed(TestId::of('LoopTest::a'), Seconds::of(0.5)),
        'unmeasured',
    ],
    'no test covering its line' => [CoverageMap::empty(), 'unmeasured'],
]);
