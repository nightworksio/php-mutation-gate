<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\Import\SourceDifference;

$paths = static fn(string ...$paths): Paths => Paths::of(...array_map(Path::of(...), $paths));
$notes = static fn(Import $import): array => array_slice(explode("\n", $import->report('infection.json5')), 1, -2);

it('notes each directory one side names and the other does not', function () use ($paths, $notes): void {
    $noted = SourceDifference::noted(Import::none(), 'source.directories', $paths('src', 'lib'), $paths('src/', 'app', 'modules'));

    expect($notes($noted))->toBe([
        'phpunit.xml\'s <source> names app, modules, which source.directories does not.',
        'source.directories names lib, which phpunit.xml\'s <source> does not.',
    ]);
});

it('notes nothing where both name the same directories, or either names none', function () use ($paths): void {
    $none = Import::of(Layer::none());

    expect(SourceDifference::noted($none, 'source.directories', $paths('src'), $paths('src')))->toEqual($none)
        ->and(SourceDifference::noted($none, 'source.directories', $paths(), $paths('src')))->toEqual($none)
        ->and(SourceDifference::noted($none, 'source.directories', $paths('src'), $paths()))->toEqual($none);
});
