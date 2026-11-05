<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Serve\ServeIdentity;

it('says what changed since a daemon started, the version first, then the lock, then the config, and nothing where nothing did', function (string $version, string $lock, string $config, string $changed): void {
    $started = ServeIdentity::of('/p', '1.0.0', 'lock', 'config');

    expect(ServeIdentity::of('/p', $version, $lock, $config)->changedSince($started))->toBe($changed);
})->with([
    'nothing' => ['1.0.0', 'lock', 'config', ''],
    'the version' => ['1.1.0', 'other', 'other', 'The gate\'s version'],
    'the lock' => ['1.0.0', 'other', 'other', 'The project\'s composer.lock'],
    'the config' => ['1.0.0', 'lock', 'other', 'The project\'s config'],
]);

it('is of the same root as another only where both name the same real path', function (): void {
    $identity = ServeIdentity::of('/p', '1.0.0', '', '');

    expect($identity->isOfRootOf(ServeIdentity::of('/p', '2.0.0', 'l', 'c')))->toBeTrue()
        ->and($identity->isOfRootOf(ServeIdentity::of('/q', '1.0.0', '', '')))->toBeFalse()
        ->and($identity->root())->toBe('/p');
});

it('reads back as it is written', function (): void {
    $identity = ServeIdentity::of('/p', '1.0.0', 'lock', 'config');
    $read = ServeIdentity::read(Node::decode($identity->written()->line()));

    expect($read->written()->line())->toBe('{"root":"/p","version":"1.0.0","lock":"lock","config":"config"}')
        ->and($read->changedSince($identity))->toBe('')
        ->and($read->isOfRootOf($identity))->toBeTrue();
});
