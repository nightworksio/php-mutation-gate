<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;

it('shortens a mutator\'s name to its last part, after its last backslash or slash', function (string $name, string $short): void {
    expect(RunnerMutatorName::of($name)->short())->toBe($short);
})->with([
    'a class name' => ['Runner\Mutators\RemoveArrayItem', 'RemoveArrayItem'],
    'the default set\'s name' => ['default/RemoveArrayItem', 'RemoveArrayItem'],
    'a short name' => ['Foreach_', 'Foreach_'],
    'a slash after a backslash' => ['Runner\set/Ternary', 'Ternary'],
]);
