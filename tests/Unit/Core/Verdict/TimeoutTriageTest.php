<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\TimeoutHeadroom;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TimeoutTriage;

/** A mutant of `src/Loop.php` from line 3 to the last, with this status, allowed this limit, whose judging tests take this long. */
function triagedMutant(MutantStatus $status, float $limit, float $tests, int $last = 3): Mutant
{
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Loop.php'), 'LessThan', '3', 0),
        '1',
        Location::of(Path::of('src/Loop.php'), Line::of(3), Line::of($last)),
        Mutation::of('LessThan', MutatorFamily::Boundary, ''),
        $status,
        Unmeasured::duration(),
    );
    $limited = $limit > 0 ? $mutant->withLimit(Seconds::of($limit)) : $mutant;

    return $tests > 0 ? $limited->withUnmutatedNeed(Seconds::of($tests)) : $limited;
}

it('kills a timeout whose tests, run unmutated under its limit, finished within it, and no other', function (
    Mutant $mutant,
    MutantJudgement $judged,
): void {
    expect(TimeoutTriage::under(TimeoutMode::Confirm)->judged($mutant))->toBe($judged);
})->with([
    'tests that took a tenth of the limit' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 1.0), MutantJudgement::KilledByTimeout],
    'tests that left the standard headroom unused' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 9.0), MutantJudgement::KilledByTimeout],
    'tests that left less than the standard headroom' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 9.5), MutantJudgement::TooSlowToJudge],
    'tests that took the whole limit' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 10.0), MutantJudgement::TooSlowToJudge],
    'tests that took longer than the limit' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 10.5), MutantJudgement::TooSlowToJudge],
    'a limit the most decided' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 300.0, 150.0), MutantJudgement::KilledByTimeout],
    'no limit known' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 0.0, 1.0), MutantJudgement::TooSlowToJudge],
    'tests that never finished unmutated' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 0.0), MutantJudgement::TooSlowToJudge],
    'a skipped mutant' => [fn(): Mutant => triagedMutant(MutantStatus::Skipped, 10.0, 1.0), MutantJudgement::TooSlowToJudge],
    'a killed mutant' => [fn(): Mutant => triagedMutant(MutantStatus::Killed, 10.0, 1.0), MutantJudgement::Killed],
]);

it('keeps the headroom it is given of a limit', function (): void {
    $tight = TimeoutTriage::under(TimeoutMode::Confirm, TimeoutHeadroom::of(0));
    $wide = TimeoutTriage::under(TimeoutMode::Confirm, TimeoutHeadroom::of(0.5));

    expect($tight->judged(triagedMutant(MutantStatus::TimedOut, 10.0, 10.0)))->toBe(MutantJudgement::KilledByTimeout)
        ->and($tight->judged(triagedMutant(MutantStatus::TimedOut, 10.0, 10.5)))->toBe(MutantJudgement::TooSlowToJudge)
        ->and($wide->judged(triagedMutant(MutantStatus::TimedOut, 10.0, 5.0)))->toBe(MutantJudgement::KilledByTimeout)
        ->and($wide->judged(triagedMutant(MutantStatus::TimedOut, 10.0, 5.5)))->toBe(MutantJudgement::TooSlowToJudge);
});

it('judges every timeout too slow to judge under timeouts.mode unjudged', function (): void {
    expect(TimeoutTriage::under(TimeoutMode::Unjudged)->judged(triagedMutant(MutantStatus::TimedOut, 10.0, 1.0)))
        ->toBe(MutantJudgement::TooSlowToJudge)
        ->and(TimeoutTriage::under(TimeoutMode::Unjudged)->judged(triagedMutant(MutantStatus::Survived, 10.0, 1.0)))
        ->toBe(MutantJudgement::Survived);
});
