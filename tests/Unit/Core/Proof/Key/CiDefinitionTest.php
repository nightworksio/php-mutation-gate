<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinition;

$pin = str_repeat('3d', 20);

it('reads the definition as it runs: no comments, no blank lines, and no commit an action is pinned at', function () use ($pin): void {
    $definition = CiDefinition::at(Path::of('.github/workflows/mutation.yml'), Contents::of(sprintf(<<<'YAML'
        # The gate.
        name: mutation

        jobs:
          mutation:
            steps:
              - uses: actions/checkout@%1$s # v7.0.1
                with:
                  fetch-depth: 0
              - uses: shivammathur/setup-php@%1$s
                  # indented comment
              - uses: actions/cache@v4
              - run: echo "#1"
        YAML, $pin)));

    expect($definition->path())->toEqual(Path::of('.github/workflows/mutation.yml'))
        ->and($definition->asItRuns())->toBe(<<<'YAML'
            name: mutation
            jobs:
              mutation:
                steps:
                  - uses: actions/checkout
                    with:
                      fetch-depth: 0
                  - uses: shivammathur/setup-php
                  - uses: actions/cache@v4
                  - run: echo "#1"
            YAML);
});

it('keeps a pin that is not a commit', function (): void {
    expect(CiDefinition::at(Path::of('ci.yml'), Contents::of('uses: actions/cache@abc # short'))->asItRuns())
        ->toBe('uses: actions/cache@abc # short');
});

it('is nothing for a run outside CI', function (): void {
    expect(CiDefinition::none()->path())->toEqual(Path::root())
        ->and(CiDefinition::none()->asItRuns())->toBe('');
});
