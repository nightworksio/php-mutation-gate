<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinition;

$pin = str_repeat('3d', 20);

it('reads the definition as it runs: without its comment lines, and with every pin and blank line', function () use ($pin): void {
    $definition = CiDefinition::at(Path::of('.github/workflows/mutation.yml'), Contents::of(sprintf(<<<'YAML'
        # The gate.
        name: mutation

        jobs:
          mutation:
            steps:
              - uses: actions/checkout@%1$s # v7.0.1
                with:
                  fetch-depth: 0
              # The runtime.
              - uses: shivammathur/setup-php@%1$s
              - run: echo "#1"
        YAML, $pin)));

    expect($definition->path())->toEqual(Path::of('.github/workflows/mutation.yml'))
        ->and($definition->asItRuns())->toBe(sprintf(<<<'YAML'
            name: mutation

            jobs:
              mutation:
                steps:
                  - uses: actions/checkout@%1$s # v7.0.1
                    with:
                      fetch-depth: 0
                  - uses: shivammathur/setup-php@%1$s
                  - run: echo "#1"
            YAML, $pin));
});
