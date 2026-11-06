<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Doctor\GatePins;
use NightWorksIO\MutationGate\Core\Runner\Version;

$old = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$new = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$release = static fn(string $commit, string $version = 'v0.4.0'): GatePin => GatePin::of(Version::of('nightworksio/mutation-gate', $version, $commit));
$definition = static fn(string $commit): string => implode("\n", [
    sprintf('      - uses: nightworksio/php-mutation-gate@%s # v0.3.0', $commit),
    sprintf('    uses: nightworksio/php-mutation-gate/.github/workflows/mutation-gate.yml@%s', $commit),
    '    image: ghcr.io/nightworksio/mutation-gate:0.3.0',
    '      - uses: actions/checkout@v4',
    '',
]);

it('names each pin of the gate that is not the installed release once, as it is written', function () use ($old, $new, $release, $definition): void {
    $stale = [
        sprintf('nightworksio/php-mutation-gate@%s # v0.3.0', $old),
        sprintf('nightworksio/php-mutation-gate/.github/workflows/mutation-gate.yml@%s', $old),
        'ghcr.io/nightworksio/mutation-gate:0.3.0',
    ];

    expect(GatePins::stale($definition($old), $release($new)))->toBe($stale)
        ->and(GatePins::stale(str_repeat($definition($old), 2), $release($new)))->toBe($stale);
});

it('names none where every pin is the installed release, or the release is not known', function () use ($new, $release, $definition): void {
    $current = str_replace('0.3.0', '0.4.0', $definition($new));

    expect(GatePins::stale($current, $release($new)))->toBe([])
        ->and(GatePins::stale($definition($new), GatePin::unknown()))->toBe([])
        ->and(GatePins::repinned($definition($new), GatePin::unknown()))->toBe($definition($new));
});

it('pins every use of the gate at the installed commit, with its version, and its image at its tag', function () use ($old, $new, $release, $definition): void {
    expect(GatePins::repinned($definition($old), $release($new)))->toBe(implode("\n", [
        sprintf('      - uses: nightworksio/php-mutation-gate@%s # v0.4.0', $new),
        sprintf('    uses: nightworksio/php-mutation-gate/.github/workflows/mutation-gate.yml@%s # v0.4.0', $new),
        '    image: ghcr.io/nightworksio/mutation-gate:0.4.0',
        '      - uses: actions/checkout@v4',
        '',
    ]));
});

it('leaves an image alone where the installed gate is a branch, which has no image tag', function () use ($old, $new, $definition): void {
    $branch = GatePin::of(Version::of('nightworksio/mutation-gate', 'dev-main', $new));

    expect(GatePins::stale($definition($old), $branch))->toHaveCount(2)
        ->and(GatePins::repinned($definition($old), $branch))->toContain('ghcr.io/nightworksio/mutation-gate:0.3.0')
        ->and(GatePins::repinned($definition($old), $branch))->toContain(sprintf('nightworksio/php-mutation-gate@%s' . "\n", $new));
});
