<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The gate's pin, where Composer lists these packages installed.
 *
 * @param list<array<string, string|array<string, string>>> $packages
 */
function gatePinOf(array $packages): GatePin
{
    $installed = Installed::decode(
        Contents::of((string) json_encode(['packages' => $packages])),
        Path::of('vendor/composer/installed.json'),
    );

    return $installed instanceof Installed ? GatePin::in($installed) : GatePin::unknown();
}

it('pins the commit Composer installed the gate from, with its version', function (): void {
    $pin = gatePinOf([
        ['name' => 'acme/other', 'version' => 'v9.0.0', 'source' => ['reference' => 'ffffffff']],
        ['name' => 'nightworksio/mutation-gate', 'version' => 'v1.2.0', 'source' => ['reference' => '0123abcd']],
    ]);

    expect([$pin->commit(), $pin->version(), $pin->isKnown()])->toBe(['0123abcd', 'v1.2.0', true]);
});

it('pins nothing it can name where Composer does not list the gate, or names no commit', function (
    GatePin $pin,
): void {
    expect([$pin->commit(), $pin->version(), $pin->isKnown()])
        ->toBe(['<the commit of a release>', '<its version>', false]);
})->with([
    'not listed' => [gatePinOf([['name' => 'acme/other', 'version' => 'v9.0.0', 'source' => ['reference' => 'ffff']]])],
    'no commit' => [gatePinOf([['name' => 'nightworksio/mutation-gate', 'version' => 'dev-main']])],
    'an empty commit' => [gatePinOf([
        ['name' => 'nightworksio/mutation-gate', 'version' => 'dev-main', 'source' => ['reference' => '']],
    ])],
    'unknown' => [GatePin::unknown()],
]);
