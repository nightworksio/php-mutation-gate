<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinition;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinitions;

$definition = static fn(string $path, string $text = 'image: php'): CiDefinition => CiDefinition::at(Path::of($path), Contents::of($text));
$paths = static fn(CiDefinitions $definitions): array => array_map(
    static fn(CiDefinition $definition): string => $definition->path()->value(),
    iterator_to_array($definitions, preserve_keys: true),
);

it('holds none outside CI', function (): void {
    expect(CiDefinitions::none())->toHaveCount(0);
});

it('keeps definitions in byte order of their paths, numbered from nought', function () use ($definition, $paths): void {
    expect($paths(CiDefinitions::of($definition('b.yml'), $definition('B.yml'), $definition('a.yml'))))->toBe(['B.yml', 'a.yml', 'b.yml']);
});

it('keeps one definition for each path, the last given', function () use ($definition): void {
    $definitions = CiDefinitions::of($definition('.gitlab-ci.yml', 'stages: [a]'), $definition('.gitlab-ci.yml', 'stages: [b]'));

    expect($definitions)->toHaveCount(1)
        ->and([...$definitions][0]->asItRuns())->toBe('stages: [b]');
});

it('adds a definition without changing the definitions it came from', function () use ($definition, $paths): void {
    $definitions = CiDefinitions::of($definition('b.yml'));

    expect($paths($definitions->with($definition('a.yml'))))->toBe(['a.yml', 'b.yml'])
        ->and($definitions)->toHaveCount(1);
});

it('says whether a path is one of its definitions', function () use ($definition): void {
    $definitions = CiDefinitions::of($definition('.gitlab-ci.yml'), $definition('ci/template.yml'));

    expect($definitions->has(Path::of('ci/template.yml')))->toBeTrue()
        ->and($definitions->has(Path::of('.gitlab-ci.yml')))->toBeTrue()
        ->and($definitions->has(Path::of('ci/other.yml')))->toBeFalse();
});
