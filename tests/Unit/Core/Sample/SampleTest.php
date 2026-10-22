<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\Unsampled;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$unit = static fn(string $path): Unit => Unit::file(Path::of($path));

it('draws nothing for a run that is not sampled', function (): void {
    $sample = Sample::none();

    expect($sample->isDrawn())->toBeFalse()
        ->and($sample->seed())->toBe('')
        ->and(count($sample->sampled()))->toBe(0)
        ->and(count($sample->unsampled()))->toBe(0);
});

it('holds the seed, confidence and margin it was drawn for', function (): void {
    $sample = Sample::drawn('a1b2c3', Confidence::NinetyNine, 1.5);

    expect($sample->isDrawn())->toBeTrue()
        ->and($sample->seed())->toBe('a1b2c3')
        ->and($sample->confidence())->toBe(Confidence::NinetyNine)
        ->and($sample->margin())->toBe(1.5);
});

it('gathers the units each tree\'s draw runs and leaves out', function () use ($unit): void {
    $sample = Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0)
        ->holding(Units::of($unit('src/A.php')), Unsampled::none()->with(Candidate::of($unit('src/B.php'), 7, NotGiven::value())))
        ->holding(Units::of($unit('app/C.php')), Unsampled::none());

    expect(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$sample->sampled()]))->toBe(['src/A.php', 'app/C.php'])
        ->and(array_map(static fn(Candidate $left): string => $left->unit()->path()->value(), [...$sample->unsampled()]))->toBe(['src/B.php']);
});

it('runs every unit but those it leaves out', function () use ($unit): void {
    $sample = Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0)
        ->holding(Units::of($unit('src/A.php')), Unsampled::none()->with(Candidate::of($unit('src/B.php'), 7, NotGiven::value())));

    $running = $sample->running(Units::of($unit('src/A.php'), $unit('src/B.php'), $unit('src/New.php')));

    expect(array_map(static fn(Unit $one): string => $one->path()->value(), [...$running]))->toBe(['src/A.php', 'src/New.php']);
});
