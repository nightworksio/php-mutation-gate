<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Growth;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$timing = static fn(string $unit, float $seconds, string $at = '2026-09-29T20:00:00Z'): Timing => Timing::of(
    Path::of($unit),
    Seconds::of($seconds),
    'pest',
    Moment::at($at),
);
$seconds = static fn(Timings $timings): array => array_map(
    static fn(Timing $timing): float => $timing->seconds()->seconds(),
    iterator_to_array($timings, preserve_keys: true),
);

it('holds nothing to begin with', function (): void {
    expect(Timings::none())->toHaveCount(0);
});

it('keeps one timing per unit, a later measurement replacing the one held', function () use ($timing, $seconds): void {
    $timings = Timings::of(
        $timing('src/A.php', 1.0),
        $timing('src/B.php', 2.0),
        $timing('src/A.php', 3.0, '2026-09-29T21:00:00Z'),
    );

    expect($seconds($timings))->toBe([3.0, 2.0])
        ->and($timings)->toHaveCount(2);
});

it('keeps the held timing over one measured before it', function () use ($timing, $seconds): void {
    $timings = Timings::of($timing('src/A.php', 3.0, '2026-09-29T21:00:00Z'), $timing('src/A.php', 1.0));

    expect($seconds($timings))->toBe([3.0]);
});

it('takes the later of two timings measured at the same instant', function () use ($timing, $seconds): void {
    expect($seconds(Timings::of($timing('src/A.php', 1.0), $timing('src/A.php', 4.0))))->toBe([4.0]);
});

it('adds a timing without changing the timings it came from', function () use ($timing): void {
    $timings = Timings::none();

    expect($timings->with($timing('123', 1.0)))->toHaveCount(1)
        ->and($timings)->toHaveCount(0);
});

it('joins another\'s timings, each unit keeping its newest', function () use ($timing, $seconds): void {
    $ours = Timings::of($timing('src/A.php', 1.0, '2026-09-29T21:00:00Z'), $timing('src/B.php', 2.0));
    $theirs = Timings::of($timing('src/A.php', 5.0), $timing('src/B.php', 6.0, '2026-09-29T21:00:00Z'), $timing('src/C.php', 7.0));

    expect($seconds($ours->and($theirs)))->toBe([1.0, 6.0, 7.0]);
});

it('keeps only the timings of units that still exist', function () use ($timing, $seconds): void {
    $timings = Timings::of($timing('src/A.php', 1.0), $timing('src/B.php', 2.0), $timing('src/C.php', 3.0));

    expect($seconds($timings->onlyFor(Paths::of(Path::of('src/C.php'), Path::of('src/A.php')))))->toBe([1.0, 3.0]);
});

it('answers what a unit took, and says so where nothing measured it', function () use ($timing): void {
    $timings = Timings::of($timing('123', 1.5));

    expect($timings->secondsFor(Path::of('123')))->toEqual(Seconds::of(1.5))
        ->and($timings->secondsFor(Path::of('src/B.php')))->toEqual(Unmeasured::duration());
});

it('builds, merges and trims timings in time linear in their number', function () use ($timing): void {
    $kept = static function (int $size) use ($timing): Closure {
        $units = array_map(static fn(int $at): string => sprintf('src/F%d.php', $at), range(1, $size));
        $older = array_map(static fn(string $unit): Timing => $timing($unit, 1.0, '2026-09-29T20:00:00Z'), $units);
        $newer = array_map(static fn(string $unit): Timing => $timing($unit, 2.0, '2026-09-29T21:00:00Z'), $units);
        $paths = Paths::of(...array_map(Path::of(...), $units));

        return static fn(): Timings => Timings::of(...$newer, ...$older)->and(Timings::of(...$older))->onlyFor($paths);
    };

    expect($kept(10)())->toHaveCount(10)
        ->and($kept(10)()->secondsFor(Path::of('src/F1.php')))->toEqual(Seconds::of(2.0))
        ->and(Growth::of(625, $kept))->toBeLessThan(Growth::LINEAR);
});
