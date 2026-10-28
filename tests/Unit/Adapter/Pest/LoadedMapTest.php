<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Adapter\Pest\Suite;
use NightWorksIO\MutationGate\Core\NotGiven;

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
