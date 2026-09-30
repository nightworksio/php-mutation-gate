<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Refused;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;

it('refuses any value, saying what to do instead', function (string $config): void {
    $refused = Refused::because('set it in the environment');

    expect($refused->read(Node::config($config)->field('with')->field('url'))->problems())
        ->toEqual([Problem::at('with.url', 'set it in the environment')])
        ->and($refused->expected())->toBe('set it in the environment')
        ->and($refused->schema()->line())->toBe('{"not":{},"description":"set it in the environment"}')
        ->and($refused->effects())->toBe([]);
})->with([
    'text' => ['{"with": {"url": "https://x"}}'],
    'a number' => ['{"with": {"url": 3}}'],
    'null' => ['{"with": {"url": null}}'],
]);
