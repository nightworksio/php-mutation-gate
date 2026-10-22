<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Draw;
use NightWorksIO\MutationGate\Core\Sample\Drawing;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\SampleSize;
use NightWorksIO\MutationGate\Core\Sample\Tally;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$drawing = static fn(): Drawing => Drawing::of(Draw::seeded('abc'), SampleSize::of(10.0, Confidence::NinetyFive, 1));
$candidate = static fn(string $path, int $mutants, Tally|NotGiven $last): Candidate => Candidate::of(
    Unit::file(Path::of($path)),
    $mutants,
    $last,
);

it('runs the first units the draw ranks, as many as the stratum\'s share of the tree and last results size it for', function () use ($drawing, $candidate): void {
    $sample = $drawing()->into(
        Sample::drawn('abc', Confidence::NinetyFive, 10.0),
        80,
        $candidate('src/A.php', 10, Tally::of(5, 10)),
        $candidate('src/B.php', 10, Tally::of(6, 10)),
        $candidate('src/C.php', 10, Tally::of(9, 10)),
        $candidate('src/D.php', 10, Tally::of(2, 10)),
    );
    $unsampled = [...$sample->unsampled()];

    expect(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$sample->sampled()]))->toBe(['src/A.php', 'src/C.php', 'src/D.php'])
        ->and(count($unsampled))->toBe(1)
        ->and($unsampled[0]->unit()->path()->value())->toBe('src/B.php')
        ->and($unsampled[0]->mutants())->toBe(10);
});

it('runs every unit where too few of them have a last result to size the sample', function () use ($drawing, $candidate): void {
    $sample = $drawing()->into(
        Sample::drawn('abc', Confidence::NinetyFive, 10.0),
        80,
        $candidate('src/A.php', 10, Tally::of(5, 10)),
        $candidate('src/B.php', 10, NotGiven::value()),
        $candidate('src/C.php', 10, NotGiven::value()),
    );

    expect(count($sample->sampled()))->toBe(3)
        ->and(count($sample->unsampled()))->toBe(0);
});

it('runs only the fewest units where the tree holds no mutant it can estimate', function () use ($drawing, $candidate): void {
    $sample = $drawing()->into(
        Sample::drawn('abc', Confidence::NinetyFive, 10.0),
        0,
        $candidate('src/A.php', 0, Tally::of(5, 10)),
        $candidate('src/B.php', 0, Tally::of(6, 10)),
    );

    expect(count($sample->sampled()))->toBe(1)
        ->and(count($sample->unsampled()))->toBe(1);
});
