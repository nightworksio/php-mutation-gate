<?php

declare(strict_types=1);

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
    'tests that took most of the limit' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 9.5), MutantJudgement::KilledByTimeout],
    'tests that took the whole limit' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 10.0), MutantJudgement::KilledByTimeout],
    'tests that took longer than the limit' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 10.5), MutantJudgement::TooSlowToJudge],
    'a limit the most decided' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 300.0, 150.0), MutantJudgement::KilledByTimeout],
    'no limit known' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 0.0, 1.0), MutantJudgement::TooSlowToJudge],
    'tests that never finished unmutated' => [fn(): Mutant => triagedMutant(MutantStatus::TimedOut, 10.0, 0.0), MutantJudgement::TooSlowToJudge],
    'a skipped mutant' => [fn(): Mutant => triagedMutant(MutantStatus::Skipped, 10.0, 1.0), MutantJudgement::TooSlowToJudge],
    'a killed mutant' => [fn(): Mutant => triagedMutant(MutantStatus::Killed, 10.0, 1.0), MutantJudgement::Killed],
]);

it('judges every timeout too slow to judge under timeouts.mode unjudged', function (): void {
    expect(TimeoutTriage::under(TimeoutMode::Unjudged)->judged(triagedMutant(MutantStatus::TimedOut, 10.0, 1.0)))
        ->toBe(MutantJudgement::TooSlowToJudge)
        ->and(TimeoutTriage::under(TimeoutMode::Unjudged)->judged(triagedMutant(MutantStatus::Survived, 10.0, 1.0)))
        ->toBe(MutantJudgement::Survived);
});
