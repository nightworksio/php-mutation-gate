<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Refused;
use NightWorksIO\MutationGate\Core\Config\Problem;

it('refuses any value, saying what to do instead', function (mixed $value): void {
    $refused = Refused::because('set it in the environment');

    expect($refused->read($value, 'with.url')->problems())
        ->toEqual([Problem::at('with.url', 'set it in the environment')])
        ->and($refused->expected())->toBe('set it in the environment')
        ->and($refused->effects())->toBe([]);
})->with(['text' => ['https://x'], 'a number' => [3], 'nothing written' => [null]]);
