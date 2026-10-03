<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\SonarSources;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Properties;
use NightWorksIO\MutationGate\Core\NotGiven;

it('reads the paths sonar.sources names, trimmed, leaving out empty ones', function (): void {
    expect(SonarSources::in(Properties::decode("sonar.sources = src , plugins/default/src,, \\\n  ./lib/\n")))
        ->toEqual(SonarSources::of(Path::of('src'), Path::of('plugins/default/src'), Path::of('lib')));
});

it('reads nothing where sonar.sources is not set, or names no path', function (string $properties): void {
    expect(SonarSources::in(Properties::decode($properties)))->toEqual(NotGiven::value());
})->with([
    'no file' => '',
    'another key' => 'sonar.tests=tests',
    'no path' => 'sonar.sources=',
    'only commas' => 'sonar.sources= , ,',
]);

it('holds a tree that is one of its paths or inside one, and no other', function (): void {
    $sources = SonarSources::of(Path::of('src'), Path::of('app/Http'));

    expect($sources->holds(Path::of('src')))->toBeTrue()
        ->and($sources->holds(Path::of('src/Core')))->toBeTrue()
        ->and($sources->holds(Path::of('app/Http/Controllers')))->toBeTrue()
        ->and($sources->holds(Path::of('app')))->toBeFalse()
        ->and($sources->holds(Path::of('srcs')))->toBeFalse()
        ->and($sources->holds(Path::root()))->toBeFalse()
        ->and(SonarSources::of(Path::root())->holds(Path::of('app/Legacy')))->toBeTrue();
});
