<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('prints the JSON Schema this package ships', function (): void {
    $printed = Commands::run(Tree::root(), 'config:schema');

    expect([$printed->code, $printed->output, $printed->errors])->toBe([
        0,
        (string) file_get_contents(Tree::at('resources/mutation-gate.schema.json')),
        '',
    ]);
});
