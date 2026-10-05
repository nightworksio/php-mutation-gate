<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\MemoryTriage;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;
use NightWorksIO\MutationGate\Tests\Support\Growth;

/** A mutant of `src/Grow.php` with this status, stopped by a cap of this many megabytes, under a suite that held this many. */
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

it('kills a mutant out of memory only where the cap holds twice what the unmutated suite held', function (
    Mutant $mutant,
    MutantJudgement $judged,
): void {
    expect(MemoryTriage::standard()->judged($mutant))->toBe($judged);
})->with([
    'well under half' => [weighedMutant(MutantStatus::OutOfMemory, 512, 100), MutantJudgement::KilledByMemoryCap],
    'half' => [weighedMutant(MutantStatus::OutOfMemory, 512, 256), MutantJudgement::KilledByMemoryCap],
    'just over half' => [weighedMutant(MutantStatus::OutOfMemory, 512, 257), MutantJudgement::TooHeavyToJudge],
    'up to the cap' => [weighedMutant(MutantStatus::OutOfMemory, 512, 512), MutantJudgement::TooHeavyToJudge],
    'no peak measured' => [weighedMutant(MutantStatus::OutOfMemory, 512, 0), MutantJudgement::TooHeavyToJudge],
    'no cap recorded' => [weighedMutant(MutantStatus::OutOfMemory, 0, 100), MutantJudgement::TooHeavyToJudge],
    'a killed mutant' => [weighedMutant(MutantStatus::Killed, 512, 100), MutantJudgement::Killed],
]);

it('weighs each mutant out of memory by the peak the plan measured, and no other', function (): void {
    $weighed = [...MemoryTriage::weighed(Mutants::of(
        weighedMutant(MutantStatus::OutOfMemory, 512, 0),
        weighedMutant(MutantStatus::Killed, 0, 0),
    ), MemoryCap::of(100, MemoryUnit::Megabytes))];

    expect($weighed[0]->unmutatedNeed())->toEqual(MemoryCap::of(100, MemoryUnit::Megabytes))
        ->and($weighed[1]->unmutatedNeed())->toEqual(Unmeasured::duration());
});

it('leaves the suite\'s need unknown where the plan measured no peak', function (): void {
    $mutants = Mutants::of(weighedMutant(MutantStatus::OutOfMemory, 512, 0));

    expect(MemoryTriage::weighed($mutants, NotGiven::value()))->toBe($mutants);
});

it('triages a mutant out of memory by memory, and a timeout by time', function (): void {
    $timedOut = weighedMutant(MutantStatus::TimedOut, 0, 0)
        ->withLimit(Seconds::of(10.0))
        ->withUnmutatedNeed(Seconds::of(1.0));

    expect(MutantTriage::under(TimeoutMode::Confirm)->judged(weighedMutant(MutantStatus::OutOfMemory, 512, 100)))
        ->toBe(MutantJudgement::KilledByMemoryCap)
        ->and(MutantTriage::under(TimeoutMode::Confirm)->judged($timedOut))->toBe(MutantJudgement::KilledByTimeout)
        ->and(MutantTriage::under(TimeoutMode::Unjudged)->judged($timedOut))->toBe(MutantJudgement::TooSlowToJudge);
});

it('weighs mutants in time linear in their number', function (): void {
    $weighing = static function (int $size): Closure {
        $mutants = Mutants::of(...array_fill(0, $size, weighedMutant(MutantStatus::OutOfMemory, 512, 0)));

        return static fn(): int => count(MemoryTriage::weighed($mutants, MemoryCap::of(100, MemoryUnit::Megabytes)));
    };

    expect($weighing(10)())->toBe(10)
        ->and(Growth::of(1000, $weighing))->toBeLessThan(Growth::LINEAR);
});
