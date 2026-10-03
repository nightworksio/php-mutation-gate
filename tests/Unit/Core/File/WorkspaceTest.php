<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;

it('keeps the gate\'s own files under .mutation-gate', function (): void {
    expect(Workspace::root())->toEqual(Path::of('.mutation-gate'));
});

it('keeps each runner\'s bridges to the registered mutators in a file of its own', function (BuiltinRunner $runner, string $path): void {
    expect(Workspace::bridges($runner))->toEqual(Path::of($path));
})->with([
    'Pest' => [BuiltinRunner::Pest, 'mutators/pest/bridges.php'],
    'Infection' => [BuiltinRunner::Infection, 'mutators/infection/bridges.php'],
]);
