<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Turbo\Handshake;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Protocol;

$said = static fn(string $name, string $version, int $protocol): string => (string) json_encode(
    ['name' => $name, 'protocol' => $protocol, 'version' => $version],
);

it('accepts a helper that is exactly the one this gate asks, on its protocol', function () use ($said): void {
    expect(Handshake::read(sprintf("%s\n", $said(Protocol::HELPER, Protocol::HELPER_VERSION, Protocol::VERSION))))
        ->toBeInstanceOf(Handshake::class);
});

it('refuses another helper, another version or another protocol, naming both sides', function () use ($said): void {
    expect(Handshake::read($said('other', Protocol::HELPER_VERSION, Protocol::VERSION)))
        ->toEqual(NotAccelerated::because(sprintf(
            'The helper is other %s on protocol %d, and this gate asks %s %s on protocol %d.',
            Protocol::HELPER_VERSION,
            Protocol::VERSION,
            Protocol::HELPER,
            Protocol::HELPER_VERSION,
            Protocol::VERSION,
        )))
        ->and(Handshake::read($said(Protocol::HELPER, '0.0.1', Protocol::VERSION)))->toBeInstanceOf(NotAccelerated::class)
        ->and(Handshake::read($said(Protocol::HELPER, Protocol::HELPER_VERSION, Protocol::VERSION + 1)))
        ->toBeInstanceOf(NotAccelerated::class);
});

it('refuses a handshake it cannot read', function (): void {
    expect(Handshake::read('not json'))->toEqual(NotAccelerated::because(
        'The helper\'s handshake is not what this gate reads: the handshake.name is missing.',
    ))
        ->and(Handshake::read('{"name":"mutation-gate-turbo","version":"0.1.0","protocol":"1"}'))
        ->toBeInstanceOf(NotAccelerated::class);
});
