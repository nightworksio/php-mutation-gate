<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Adapter\Pest\Suite;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

afterEach(function (): void {
    LoadedMap::remember([]);
});

/**
 * The map Pest loaded, its tests taking these seconds.
 *
 * @param array<string, float> $seconds by test
 */
function loadedMapOf(array $seconds): void
{
    LoadedMap::remember(['testResults' => array_map(static fn(float $time): array => ['time' => $time], $seconds)]);
}

it('keeps every test the map ran, and how long each took where the map timed it', function (): void {
    loadedMapOf(['T::a' => 0.25, 'T::never' => 0.0]);

    expect(LoadedMap::tests())->toBe(['T::a', 'T::never'])
        ->and(LoadedMap::secondsOf('T::a'))->toBe(0.25)
        ->and(LoadedMap::secondsOf('T::never'))->toEqual(NotGiven::value())
        ->and(LoadedMap::secondsOf('T::unknown'))->toEqual(NotGiven::value());
});

it('counts the map\'s tests once, and again once it reads another map', function (): void {
    loadedMapOf(['T::a' => 0.25]);
    $first = LoadedMap::suite();
    loadedMapOf(['T::b' => 0.25]);

    expect(LoadedMap::suite())->toBe(LoadedMap::suite())
        ->and($first)->toEqual(Suite::named(['T::a']))
        ->and(LoadedMap::suite())->toEqual(Suite::named(['T::b']));
});

it('gives every test\'s own time once, and again once it reads another map', function (): void {
    loadedMapOf(['T::a' => 0.25, 'T::c' => 0.5]);
    $first = LoadedMap::times();
    loadedMapOf(['T::b' => 1.0]);

    expect(LoadedMap::times())->toBe(LoadedMap::times())
        ->and([...$first])->toEqual([TestTime::of(TestId::of('T::a'), Seconds::of(0.25)), TestTime::of(TestId::of('T::c'), Seconds::of(0.5))])
        ->and([...LoadedMap::times()])->toEqual([TestTime::of(TestId::of('T::b'), Seconds::of(1.0))]);
});

it('gives the seconds of each test the map timed at all, and none of one it ran in no time', function (): void {
    loadedMapOf(['T::a' => 0.25, 'T::never' => 0.0, 'T::b' => 1.5]);

    expect(LoadedMap::timed())->toBe(['T::a' => 0.25, 'T::b' => 1.5]);
});

it('hands the map Pest loaded once, so nothing after loads a second copy, and none where Pest loaded no map', function (): void {
    $data = new ProcessedCodeCoverageData();
    $data->setTestIds(['Tests\\A::a']);
    $data->setLineCoverage(['src/Money.php' => [3 => [0 => 1]]]);
    LoadedMap::remember(['basePath' => '/p', 'codeCoverage' => $data, 'testResults' => ['Tests\\A::a' => ['time' => 0.5]]]);

    $first = LoadedMap::taken();
    $second = LoadedMap::taken();
    LoadedMap::remember(['testResults' => []]);

    expect($first instanceof CoverageFile ? [...$first->testsCovering(DiskPath::of('/p/src/Money.php'), Line::of(3), Line::of(3))] : $first)
        ->toEqual([TestId::of('Tests\\A::a')])
        ->and($first instanceof CoverageFile ? $first->seconds() : $first)->toBe(0.5)
        ->and($second)->toEqual(NotGiven::value())
        ->and(LoadedMap::taken())->toEqual(NotGiven::value());
});
