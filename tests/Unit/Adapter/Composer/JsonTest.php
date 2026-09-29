<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Composer\Json;

it('reads a field of an object, and an empty list for one it lacks or a value that is no object', function (): void {
    expect(Json::field(['name' => 'acme/app'], 'name'))->toBe('acme/app')
        ->and(Json::field(['name' => 'acme/app'], 'type'))->toBe([])
        ->and(Json::field('acme/app', 'name'))->toBe([]);
});

it('reads every string a value holds, one level of lists and maps deep', function (): void {
    expect(Json::strings('src/'))->toBe(['src/'])
        ->and(Json::strings(['src/', 'lib/']))->toBe(['src/', 'lib/'])
        ->and(Json::strings(['App\\' => 'src/', 'Lib\\' => ['lib/', 'vendor/lib/']]))->toBe(['src/', 'lib/', 'vendor/lib/'])
        ->and(Json::strings([1, true, ['src/', 2]]))->toBe(['src/'])
        ->and(Json::strings(null))->toBe([]);
});
