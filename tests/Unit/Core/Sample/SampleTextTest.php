<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\SampleText;
use NightWorksIO\MutationGate\Core\Sample\Unsampled;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

it('says nothing of a run that is not sampled', function (): void {
    expect(SampleText::planned(Sample::none()))->toBe([]);
});

it('says how many units it sampled, from which seed, for which margin and confidence', function (): void {
    $sample = Sample::drawn('a1b2c3', Confidence::NinetyNine, 1.5)->holding(
        Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php'))),
        Unsampled::none()->with(Candidate::of(Unit::file(Path::of('src/C.php')), 3, NotGiven::value())),
    );

    expect(SampleText::planned($sample))->toBe([
        'Sampled 2 of the 3 units no run knows exactly, drawn from a1b2c3, for 1.50 points either side at 99% confidence.',
    ]);
});

it('says that it has nothing to sample where every tree runs whole', function (): void {
    expect(SampleText::planned(Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0)))->toBe([
        '--sample has nothing to sample: every tree is held to 100, or each unit it runs is known exactly.',
    ]);
});
