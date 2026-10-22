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
use NightWorksIO\MutationGate\Core\Unit\Units;

$drawing = static fn(): Drawing => Drawing::of(Draw::seeded('abc'), SampleSize::of(10.0, Confidence::NinetyFive, 1));
$candidate = static fn(string $path, int $mutants, Tally|NotGiven $last): Candidate => Candidate::of(
    Unit::file(Path::of($path)),
    $mutants,
    $last,
);
$unknown = static fn(string ...$paths): Units => Units::of(...array_map(static fn(string $path): Unit => Unit::file(Path::of($path)), $paths));

it('runs the first units the draw ranks, as many as the stratum\'s share of the tree and last results size it for', function () use ($drawing, $candidate, $unknown): void {
    $sample = $drawing()->into(
        Sample::drawn('abc', Confidence::NinetyFive, 10.0),
        $unknown('src/A.php', 'src/B.php', 'src/C.php', 'src/D.php'),
        $candidate('src/A.php', 10, Tally::of(5, 10)),
        $candidate('src/B.php', 10, Tally::of(6, 10)),
        $candidate('src/C.php', 10, Tally::of(9, 10)),
        $candidate('src/D.php', 10, Tally::of(2, 10)),
        $candidate('src/Known.php', 40, Tally::of(40, 40)),
    );
    $unsampled = [...$sample->unsampled()];

    expect(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$sample->sampled()]))->toBe(['src/A.php', 'src/C.php', 'src/D.php'])
        ->and(count($unsampled))->toBe(1)
        ->and($unsampled[0]->unit()->path()->value())->toBe('src/B.php')
        ->and($unsampled[0]->mutants())->toBe(10);
});

it('runs every unit where too few of them have a last result to size the sample', function () use ($drawing, $candidate, $unknown): void {
    $sample = $drawing()->into(
        Sample::drawn('abc', Confidence::NinetyFive, 10.0),
        $unknown('src/A.php', 'src/B.php', 'src/C.php'),
        $candidate('src/A.php', 10, Tally::of(5, 10)),
        $candidate('src/B.php', 10, NotGiven::value()),
        $candidate('src/C.php', 10, NotGiven::value()),
        $candidate('src/Known.php', 50, Tally::of(8, 10)),
    );

    expect(count($sample->sampled()))->toBe(3)
        ->and(count($sample->unsampled()))->toBe(0);
});

it('runs only the fewest units where the tree holds no mutant it can estimate', function () use ($drawing, $candidate, $unknown): void {
    $sample = $drawing()->into(
        Sample::drawn('abc', Confidence::NinetyFive, 10.0),
        $unknown('src/A.php', 'src/B.php'),
        $candidate('src/A.php', 0, Tally::of(5, 10)),
        $candidate('src/B.php', 0, Tally::of(6, 10)),
    );

    expect(count($sample->sampled()))->toBe(1)
        ->and(count($sample->unsampled()))->toBe(1);
});

it('sizes the sample by the share of the tree\'s mutants that the units no run knows exactly hold', function () use ($drawing, $candidate, $unknown): void {
    $paths = array_map(static fn(int $index): string => sprintf('src/U%d.php', $index), range(0, 39));
    $stratum = array_map(
        static fn(int $index): Candidate => $candidate($paths[$index], $index < 2 ? 1 : 0, Tally::of([5, 6, 9, 2][$index % 4], 10)),
        range(0, 39),
    );
    $sample = $drawing()->into(
        Sample::drawn('abc', Confidence::NinetyFive, 10.0),
        $unknown(...$paths),
        ...[...$stratum, $candidate('src/Known.php', 2, Tally::of(2, 2))],
    );

    expect(count($sample->sampled()))->toBe(6)
        ->and(count($sample->unsampled()))->toBe(34);
});
