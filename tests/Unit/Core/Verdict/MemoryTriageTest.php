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
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\MemoryTriage;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;

/** A mutant of `src/Grow.php` with this status, stopped by a cap of this many megabytes, whose control held this many. */
function weighedMutant(MutantStatus $status, int $cap, int $peak): Mutant
{
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Grow.php'), 'Increment', '7', 0),
        '1',
        Location::of(Path::of('src/Grow.php'), Line::of(7), Line::of(7)),
        Mutation::of('Increment', MutatorFamily::Arithmetic, ''),
        $status,
        Unmeasured::duration(),
    );
    $capped = $cap > 0 ? $mutant->withLimit(MemoryCap::of($cap, MemoryUnit::Megabytes)) : $mutant;

    return $peak > 0 ? $capped->withUnmutatedNeed(MemoryCap::of($peak, MemoryUnit::Megabytes)) : $capped;
}

it('kills a mutant out of memory only where its tests, run unmutated under the same cap, finished under it', function (
    Mutant $mutant,
    MutantJudgement $judged,
): void {
    expect(MemoryTriage::standard()->judged($mutant))->toBe($judged);
})->with([
    'a control that held a fifth of the cap' => [fn(): Mutant => weighedMutant(MutantStatus::OutOfMemory, 512, 100), MutantJudgement::KilledByMemoryCap],
    'a control that held most of the cap' => [fn(): Mutant => weighedMutant(MutantStatus::OutOfMemory, 512, 500), MutantJudgement::KilledByMemoryCap],
    'a control that held the whole cap' => [fn(): Mutant => weighedMutant(MutantStatus::OutOfMemory, 512, 512), MutantJudgement::KilledByMemoryCap],
    'a peak over the cap' => [fn(): Mutant => weighedMutant(MutantStatus::OutOfMemory, 512, 513), MutantJudgement::TooHeavyToJudge],
    'no control finished' => [fn(): Mutant => weighedMutant(MutantStatus::OutOfMemory, 512, 0), MutantJudgement::TooHeavyToJudge],
    'no cap recorded' => [fn(): Mutant => weighedMutant(MutantStatus::OutOfMemory, 0, 100), MutantJudgement::TooHeavyToJudge],
    'a killed mutant' => [fn(): Mutant => weighedMutant(MutantStatus::Killed, 512, 100), MutantJudgement::Killed],
]);

it('triages a mutant out of memory by memory, and a timeout by time', function (): void {
    $timedOut = weighedMutant(MutantStatus::TimedOut, 0, 0)
        ->withLimit(Seconds::of(10.0))
        ->withUnmutatedNeed(Seconds::of(1.0));

    expect(MutantTriage::under(TimeoutMode::Confirm)->judged(weighedMutant(MutantStatus::OutOfMemory, 512, 100)))
        ->toBe(MutantJudgement::KilledByMemoryCap)
        ->and(MutantTriage::under(TimeoutMode::Confirm)->judged($timedOut))->toBe(MutantJudgement::KilledByTimeout)
        ->and(MutantTriage::under(TimeoutMode::Unjudged)->judged($timedOut))->toBe(MutantJudgement::TooSlowToJudge);
});
