<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Reach\DefinitionLine;
use NightWorksIO\MutationGate\Core\Reach\LineRole;

it('reads a key with nothing but a comment after it as opening a value, and a value with a hash in it as holding one', function (string $line, LineRole $role): void {
    expect(DefinitionLine::read($line)->role())->toBe($role);
})->with([
    'nothing after the key' => ['run:', LineRole::Opens],
    'a comment after the key' => ['run: # the tests', LineRole::Opens],
    'a value ending in a hash' => ['run: echo#', LineRole::Holds],
]);
