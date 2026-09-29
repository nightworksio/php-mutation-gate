<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

$seconds = static fn(Timings $timings): array => array_map(static fn(Timing $timing): float => $timing->seconds()->seconds(), iterator_to_array($timings, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(Timings::none())->toHaveCount(0);
});

it('keeps one timing per unit, the newest replacing the older', function () use ($seconds): void {
    $timings = Timings::of(Timing::of(Path::of('src/A.php'), Seconds::of(1.0)), Timing::of(Path::of('src/B.php'), Seconds::of(2.0)), Timing::of(Path::of('src/A.php'), Seconds::of(3.0)));

    expect($seconds($timings))->toBe([3.0, 2.0])
        ->and($timings)->toHaveCount(2);
});

it('adds a timing without changing the timings it came from', function (): void {
    $timings = Timings::none();

    expect($timings->with(Timing::of(Path::of('123'), Seconds::of(1.0))))->toHaveCount(1)
        ->and($timings)->toHaveCount(0);
});

it('answers what a unit took, and says so where nothing measured it', function (): void {
    $timings = Timings::of(Timing::of(Path::of('123'), Seconds::of(1.5)));

    expect($timings->secondsFor(Path::of('123')))->toEqual(Seconds::of(1.5))
        ->and($timings->secondsFor(Path::of('src/B.php')))->toEqual(Unmeasured::duration());
});
