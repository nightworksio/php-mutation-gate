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

it('holds every class of the builder and each value of the core a config names, and no other class', function (): void {
    foreach ([Gate::class, Runner::class, 'NightWorksIO\MutationGate\Config\Anything', Glob::class, MemoryCap::class, MemoryUnit::class, Withheld::class, Workers::class] as $class) {
        expect(BuilderClasses::holds($class))->toBeTrue();
    }

    foreach ([Version::class, 'NightWorksIO\MutationGate\ConfigX\Gate', 'Acme\Config\Gate', 'Glob', 'SplFileObject'] as $class) {
        expect(BuilderClasses::holds($class))->toBeFalse();
    }
});
