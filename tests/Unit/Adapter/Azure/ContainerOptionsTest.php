<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\ContainerOptions;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('reads the account, the containers, the prefix and the public URL', function (): void {
    $options = ContainerOptions::read(Configs::options(
        '{"account": "acme", "container": "ledgers", "prefix": "gate", "publicContainer": "public", "publicUrl": "https://acme.blob.core.windows.net/public"}',
    ));
    $bare = ContainerOptions::read(Configs::options('{"account": "acme", "container": "ledgers", "prefix": "gate"}'));

    expect($options instanceof ContainerOptions
        ? [$options->account(), $options->container(), $options->prefix(), $options->publicContainer(), $options->publicUrl()]
        : [])
        ->toBe(['acme', 'ledgers', 'gate', 'public', 'https://acme.blob.core.windows.net/public'])
        ->and($bare instanceof ContainerOptions ? [$bare->publicContainer(), $bare->publicUrl()] : [])
        ->toEqual([NotGiven::value(), NotGiven::value()]);
});

it('says why options that miss what it needs, or give what is no text, open none', function (string $options, Problem $problem): void {
    expect(ContainerOptions::read(Configs::options($options)))->toEqual(Invalid::because($problem));
})->with([
    'no account' => ['{"container": "c", "prefix": "p"}', Problem::at('account', 'expected the account, got nothing')],
    'no container' => ['{"account": "a", "prefix": "p"}', Problem::at('container', 'expected the container, got nothing')],
    'no prefix' => ['{"account": "a", "container": "c"}', Problem::at('prefix', 'expected the prefix, got nothing')],
    'a public container that is no text' => ['{"account": "a", "container": "c", "prefix": "p", "publicContainer": 1}', Problem::at('publicContainer', 'expected text, got 1')],
    'a public URL that is no text' => ['{"account": "a", "container": "c", "prefix": "p", "publicUrl": 1}', Problem::at('publicUrl', 'expected text, got 1')],
]);
