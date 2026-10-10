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

const GATE_COMMIT = '0123456789abcdef0123456789abcdef01234567';

it('pins the commit Composer installed the gate from, with its version', function (): void {
    $pin = gatePinOf([
        ['name' => 'acme/other', 'version' => 'v9.0.0', 'source' => ['reference' => 'ffffffff']],
        ['name' => 'nightworksio/mutation-gate', 'version' => 'v1.2.0', 'source' => ['reference' => GATE_COMMIT]],
    ]);

    expect([$pin->commit(), $pin->version(), $pin->isKnown(), $pin->pin()])
        ->toBe([GATE_COMMIT, 'v1.2.0', true, sprintf('%s # v1.2.0', GATE_COMMIT)]);
});

it('pins nothing it can name where Composer does not list the gate, or names no commit', function (
    GatePin $pin,
): void {
    expect([$pin->commit(), $pin->version(), $pin->isKnown(), $pin->pin()])
        ->toBe(['<the commit of a release>', '<its version>', false, '<the commit of a release>']);
})->with([
    'not listed' => [fn(): GatePin => gatePinOf([['name' => 'acme/other', 'version' => 'v9.0.0', 'source' => ['reference' => 'ffff']]])],
    'no commit' => [fn(): GatePin => gatePinOf([['name' => 'nightworksio/mutation-gate', 'version' => 'dev-main']])],
    'an empty commit' => [fn(): GatePin => gatePinOf([
        ['name' => 'nightworksio/mutation-gate', 'version' => 'dev-main', 'source' => ['reference' => '']],
    ])],
    'unknown' => [fn(): GatePin => GatePin::unknown()],
    'no full commit' => [fn(): GatePin => gatePinOf([
        ['name' => 'nightworksio/mutation-gate', 'version' => 'v1.2.0', 'source' => ['reference' => '0123abcd']],
    ])],
    'a version a comment cannot hold' => [fn(): GatePin => gatePinOf([
        ['name' => 'nightworksio/mutation-gate', 'version' => "v1\n- run: evil", 'source' => ['reference' => GATE_COMMIT]],
    ])],
]);

it('pins a branch Composer installed by its commit alone, since its version names no tag', function (
    string $version,
): void {
    $pin = gatePinOf([
        ['name' => 'nightworksio/mutation-gate', 'version' => $version, 'source' => ['reference' => GATE_COMMIT]],
    ]);

    expect([$pin->isKnown(), $pin->pin()])->toBe([true, GATE_COMMIT]);
})->with(['a branch' => ['dev-main'], 'an aliased branch' => ['2.x-dev']]);
