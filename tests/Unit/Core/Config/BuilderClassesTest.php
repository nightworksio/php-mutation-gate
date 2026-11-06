<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Core\Config\BuilderClasses;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;

it('holds every class of the builder and each value of the core a config names, and no other class', function (): void {
    foreach ([Gate::class, Runner::class, 'NightWorksIO\MutationGate\Config\Anything', Glob::class, MemoryCap::class, MemoryUnit::class, ServeStart::class, Withheld::class, Workers::class] as $class) {
        expect(BuilderClasses::holds($class))->toBeTrue();
    }

    foreach ([Version::class, 'NightWorksIO\MutationGate\ConfigX\Gate', 'Acme\Config\Gate', 'Glob', 'SplFileObject'] as $class) {
        expect(BuilderClasses::holds($class))->toBeFalse();
    }
});

it('names every class of the builder by its namespace, the interface they share aside', function (): void {
    $builder = [];
    $files = glob(sprintf('%s/../../../../src/Config/*.php', __DIR__));

    foreach (is_array($files) ? $files : [] as $file) {
        $class = sprintf('%s\\%s', BuilderClasses::BUILDER, basename($file, '.php'));
        $builder = interface_exists($class) ? $builder : [...$builder, basename($file, '.php')];
    }

    expect($builder)->not->toBe([])
        ->and(array_keys(array_filter(BuilderClasses::CLASSES, static fn(string $namespace): bool => $namespace === BuilderClasses::BUILDER)))
        ->toEqualCanonicalizing($builder);
});
