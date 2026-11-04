<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\MutatorTally;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Tallies;

it('counts a mutant as killed, survived or ignored by its judgement, and every mutant and its time', function (): void {
    $tally = MutatorTally::of('pest', 'Plus');

    foreach (MutantJudgement::cases() as $judgement) {
        $tally = $tally->after($judgement, Seconds::of(0.5));
    }

    expect([$tally->runner(), $tally->mutator(), $tally->mutants(), $tally->killed(), $tally->survived(), $tally->ignored()])
        ->toBe(['pest', 'Plus', count(MutantJudgement::cases()), 5, 2, 3])
        ->and($tally->seconds())->toEqual(Seconds::of(0.5 * count(MutantJudgement::cases())))
        ->and($tally->judged())->toBe(7);
});

it('counts each kill as killed, each survivor or uncovered mutant as survived, and each ignore as ignored', function (MutantJudgement $judgement, array $counts): void {
    $tally = MutatorTally::of('pest', 'Plus')->after($judgement, Seconds::none());

    expect([$tally->killed(), $tally->survived(), $tally->ignored()])->toBe($counts);
})->with([
    'killed' => [MutantJudgement::Killed, [1, 0, 0]],
    'killed by static analysis' => [MutantJudgement::KilledByStaticAnalysis, [1, 0, 0]],
    'errored' => [MutantJudgement::Errored, [1, 0, 0]],
    'killed by a timeout' => [MutantJudgement::KilledByTimeout, [1, 0, 0]],
    'killed by the memory cap' => [MutantJudgement::KilledByMemoryCap, [1, 0, 0]],
    'survived' => [MutantJudgement::Survived, [0, 1, 0]],
    'uncovered' => [MutantJudgement::Uncovered, [0, 1, 0]],
    'ignored' => [MutantJudgement::Ignored, [0, 0, 1]],
    'ignored by a marker' => [MutantJudgement::IgnoredByMarker, [0, 0, 1]],
    'equivalent' => [MutantJudgement::Equivalent, [0, 0, 1]],
    'flaky' => [MutantJudgement::Flaky, [0, 0, 0]],
    'unjudged' => [MutantJudgement::Unjudged, [0, 0, 0]],
    'too slow to judge' => [MutantJudgement::TooSlowToJudge, [0, 0, 0]],
    'too heavy to judge' => [MutantJudgement::TooHeavyToJudge, [0, 0, 0]],
    'fails unmutated' => [MutantJudgement::FailsUnmutated, [0, 0, 0]],
]);

it('rates kills over judged mutants, ignores over every mutant, and seconds over kills', function (): void {
    $tally = Tallies::of('pest', 'Plus', mutants: 10, killed: 3, survived: 1, ignored: 4, seconds: 6.0);

    expect($tally->killRate())->toEqual(Percentage::parse(75))
        ->and($tally->ignoredShare())->toEqual(Percentage::parse(40))
        ->and($tally->secondsPerKill())->toEqual(Seconds::of(2.0));
});

it('rates nothing it has nothing to rate', function (): void {
    $none = MutatorTally::of('pest', 'Plus');

    expect($none->killRate())->toBeInstanceOf(NotGiven::class)
        ->and($none->ignoredShare())->toBeInstanceOf(NotGiven::class)
        ->and($none->secondsPerKill())->toBeInstanceOf(NotGiven::class);
});

it('reads no tally whose counts cannot be one', function (int $mutants, int $killed, int $survived, int $ignored, float $seconds): void {
    expect(MutatorTally::written('pest', 'Plus', $mutants, $killed, $survived, $ignored, $seconds))->toBeInstanceOf(NotGiven::class);
})->with([
    'a negative kill count' => [1, -1, 0, 0, 0.0],
    'a negative survivor count' => [1, 0, -1, 0, 0.0],
    'a negative ignore count' => [1, 0, 0, -1, 0.0],
    'negative seconds' => [1, 0, 0, 0, -1.0],
    'more counted than made' => [2, 1, 1, 1, 0.0],
]);

it('reads a tally whose counts fill every mutant it made', function (): void {
    expect(MutatorTally::written('pest', 'Plus', mutants: 3, killed: 1, survived: 1, ignored: 1, seconds: 0.0))
        ->toBeInstanceOf(MutatorTally::class);
});
