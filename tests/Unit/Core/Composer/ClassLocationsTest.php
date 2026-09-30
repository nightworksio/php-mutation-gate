<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Composer\AutoloadKind;
use NightWorksIO\MutationGate\Core\Composer\ClassLocations;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;

$files = static fn(ClassLocations $locations, string $class): array => array_map(
    static fn(Path $file): string => $file->value(),
    [...$locations->files($class)],
);

it('maps a class to a file below each directory of every psr-4 and psr-0 prefix that is its own', function () use ($files): void {
    $locations = ClassLocations::of(
        Path::of('packages/money'),
        Node::decode('{"psr-4": {"Acme\\\\": ["src/", "lib"], "Other\\\\": "other/"}, "psr-0": {"Acme_": "legacy/", "Acme\\\\": "old/"}}'),
        Node::decode('{"psr-4": {"Acme\\\\Tests\\\\": "tests/", "": "fallback/"}}'),
    );

    expect($files($locations, '\\Acme\\Money\\Cents'))->toBe([
        'packages/money/src/Money/Cents.php',
        'packages/money/lib/Money/Cents.php',
        'packages/money/old/Acme/Money/Cents.php',
        'packages/money/fallback/Acme/Money/Cents.php',
    ])->and($files($locations, 'Acme_Money_Cents'))->toBe([
        'packages/money/legacy/Acme/Money/Cents.php',
        'packages/money/fallback/Acme_Money_Cents.php',
    ]);
});

it('maps no class where no prefix is its own, or an autoload is not a map', function () use ($files): void {
    expect($files(ClassLocations::of(Path::root(), Node::decode('{"psr-4": {"Acme\\\\": "src/"}}')), 'Other\\Money'))->toBe([])
        ->and($files(ClassLocations::of(Path::root(), Node::decode('{"psr-4": ["src/"]}')), 'Acme\\Money'))->toBe([]);
});

it('maps a class by name only where the autoload kind names classes', function (): void {
    expect(AutoloadKind::Psr4->fileOf('Acme\\Money\\Cents', 'Acme\\'))->toBe('Money/Cents.php')
        ->and(AutoloadKind::Psr0->fileOf('Acme\\Money_Cents', 'Acme'))->toBe('Acme/Money/Cents.php')
        ->and(AutoloadKind::Psr4->fileOf('Acme\\Money', 'Other\\'))->toBe('')
        ->and(AutoloadKind::Classmap->fileOf('Acme\\Money', ''))->toBe('')
        ->and(AutoloadKind::Files->fileOf('Acme\\Money', ''))->toBe('');
});
