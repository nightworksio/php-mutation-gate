<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$map = static fn(): CoverageMap => CoverageMap::of(CoveredLine::of(Path::of('src/Idle.php'), 4), CoveredLine::of(Path::of('src/Money.php'), 30))
    ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::adds'))
    ->covered(Path::of('src/Tax.php'), Line::of(2), TestId::of('TaxTest::rates'))
    ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::subtracts'))
    ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.25))
    ->executing(Path::of('src/Money.php'), ExecutedMethod::of('add', 10, 13))
    ->executing(Path::of('src/Tax.php'), ExecutedMethod::of('rate', 1, 3));

$alone = static fn(string ...$files): Paths => Paths::of(...array_map(Path::of(...), $files));

it('writes some files alone as the map of them alone writes them, every test kept, in the order the map read them', function (string ...$files) use ($map, $alone): void {
    expect(MapText::of($map())->onlyFor($alone(...$files))->written(Unplaced::map(), new NotGiven()))
        ->toBe(MapText::of($map()->onlyFor($alone(...$files)))->written(Unplaced::map(), new NotGiven()));
})->with([
    'none' => [],
    'a file the map does not hold' => ['src/Gone.php'],
    'a file only missed' => ['src/Idle.php'],
    'files out of the map\'s order' => ['src/Tax.php', 'src/Idle.php', 'src/Money.php'],
    'a file with methods and one without' => ['src/Money.php', 'src/Idle.php'],
]);

it('writes a file\'s covered lines before the lines missed in any file, as the whole map does', function () use ($map): void {
    expect(MapText::of($map())->onlyFor(Paths::of(Path::of('src/Idle.php'), Path::of('src/Tax.php')))->written(Unplaced::map(), new NotGiven()))
        ->toBe(JsonText::compact([
            'format' => 1,
            'tests' => [['id' => 'MoneyTest::adds', 'seconds' => 0.25], ['id' => 'TaxTest::rates'], ['id' => 'MoneyTest::subtracts']],
            'files' => ['src/Tax.php' => ['2' => [1]], 'src/Idle.php' => ['4' => []]],
            'methods' => ['src/Tax.php' => [['name' => 'rate', 'start' => 1, 'end' => 3]]],
        ]));
});
