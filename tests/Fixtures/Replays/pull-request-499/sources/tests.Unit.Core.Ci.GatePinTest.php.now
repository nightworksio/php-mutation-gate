<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\Version;

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

    return GatePin::in($installed);
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
    'nothing Composer lists can be read' => [fn(): GatePin => GatePin::in(CannotJudge::because('vendor/composer/installed.json is not there.'))],
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

it('pins the commit and version built into the gate\'s PHAR, and nothing where it names no commit', function (): void {
    $built = GatePin::of(Version::of('nightworksio/mutation-gate', '1.2.0', GATE_COMMIT));
    $branch = GatePin::of(Version::of('nightworksio/mutation-gate', 'dev-main', GATE_COMMIT));
    $unpinned = GatePin::of(Version::of('nightworksio/mutation-gate', '1.2.0', 'abc123'));
    $spaced = GatePin::of(Version::of('nightworksio/mutation-gate', '1.2 beta', GATE_COMMIT));

    expect($built->pin())->toBe(sprintf('%s # 1.2.0', GATE_COMMIT))
        ->and($branch->pin())->toBe(GATE_COMMIT)
        ->and($unpinned->isKnown())->toBeFalse()
        ->and($spaced->isKnown())->toBeFalse();
});

it('pins the version built into the PHAR it runs from, and otherwise the one Composer lists', function (): void {
    $built = Version::of('nightworksio/mutation-gate', 'v1.3.0', GATE_COMMIT);
    $installed = CannotJudge::because('vendor/composer/installed.json is not there.');

    expect(GatePin::running(OwnArchive::at('/opt/mutation-gate.phar'), $built, $installed)->pin())
        ->toBe(sprintf('%s # v1.3.0', GATE_COMMIT))
        ->and(GatePin::running(NotGiven::value(), $built, $installed)->isKnown())->toBeFalse();
});

it('names the container image a release is tagged as, without its tag\'s v, and stands in for any other\'s version', function (): void {
    $tagged = GatePin::of(Version::of('nightworksio/mutation-gate', 'v1.4.0', GATE_COMMIT));
    $built = GatePin::of(Version::of('nightworksio/mutation-gate', '1.4.0', GATE_COMMIT));
    $branch = GatePin::of(Version::of('nightworksio/mutation-gate', 'dev-main', GATE_COMMIT));

    expect($tagged->image())->toBe('ghcr.io/nightworksio/mutation-gate:1.4.0')
        ->and($built->image())->toBe('ghcr.io/nightworksio/mutation-gate:1.4.0')
        ->and($branch->image())->toBe('ghcr.io/nightworksio/mutation-gate:<its version>')
        ->and(GatePin::unknown()->image())->toBe('ghcr.io/nightworksio/mutation-gate:<its version>');
});
