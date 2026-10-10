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
    'no account' => ['{"container": "ledgers", "prefix": "p"}', fn(): Problem => Problem::at('account', 'expected the account, got nothing')],
    'no container' => ['{"account": "acme", "prefix": "p"}', fn(): Problem => Problem::at('container', 'expected the container, got nothing')],
    'no prefix' => ['{"account": "acme", "container": "ledgers"}', fn(): Problem => Problem::at('prefix', 'expected the prefix, got nothing')],
    'a public container that is no text' => ['{"account": "acme", "container": "ledgers", "prefix": "p", "publicContainer": 1}', fn(): Problem => Problem::at('publicContainer', 'expected text, got 1')],
    'a public URL that is no text' => ['{"account": "acme", "container": "ledgers", "prefix": "p", "publicUrl": 1}', fn(): Problem => Problem::at('publicUrl', 'expected text, got 1')],
]);

it('refuses an account its host cannot hold, or a container its path cannot, so the token reaches only Azure', function (string $field, string $name): void {
    $options = ['account' => 'acme', 'container' => 'ledgers', 'prefix' => 'p', $field => $name];
    $expected = $field === 'account' ? 'a storage account name' : 'a container name';

    expect(ContainerOptions::read(Configs::options((string) json_encode($options))))
        ->toEqual(Invalid::because(Problem::at($field, sprintf('expected %s, got %s', $expected, json_encode($name, JSON_UNESCAPED_SLASHES)))));
})->with([
    'another host and a query' => ['account', 'evil.example/x?'],
    'user information' => ['account', 'acme@evil.example'],
    'a subdomain' => ['account', 'acme.evil'],
    'a port' => ['account', 'acme:443'],
    'a fragment' => ['account', 'acme#x'],
    'an encoded slash' => ['account', 'acme%2fx'],
    'white space' => ['account', 'ac me'],
    'too short' => ['account', 'ab'],
    'a slash after another host' => ['account', 'evil.com/'],
    'a signature in a query' => ['account', 'x?sig='],
    'a bare fragment' => ['account', 'a#'],
    'two dots' => ['account', '..'],
    'an uppercase account' => ['account', 'ACME'],
    'another host in the container' => ['container', 'evil.com/'],
    'a signature in the container' => ['container', 'x?sig='],
    'a fragment in the container' => ['container', 'a#'],
    'two dots as the container' => ['container', '..'],
    'an uppercase container' => ['container', 'Ledgers'],
    'a parent directory' => ['container', '../x'],
    'a query in the container' => ['container', 'ledgers?comp=list'],
    'a parent directory as the public container' => ['publicContainer', '../x'],
]);
