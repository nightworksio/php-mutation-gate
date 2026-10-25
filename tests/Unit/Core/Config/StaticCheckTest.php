<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;

it('writes auto, none and each analyser built in by the builder method of its name, and any other by uses()', function (): void {
    $php = static fn(string $tool): PhpCalls => StaticCheck::of(tool: Choice::of($tool, Options::none()))->php(ProjectRoot::origin());

    expect(array_map($php, ['auto', 'none', 'mago', 'phpstan', 'psalm', 'acme']))->toEqual([
        PhpCalls::inWith('StaticCheck::auto()'),
        PhpCalls::inWith('StaticCheck::none()'),
        PhpCalls::inWith('StaticCheck::mago()'),
        PhpCalls::inWith('StaticCheck::phpstan()'),
        PhpCalls::inWith('StaticCheck::psalm()'),
        PhpCalls::inWith("StaticCheck::uses('acme')"),
    ]);
});
