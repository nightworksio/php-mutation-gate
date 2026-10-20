<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\GateRelease;
use NightWorksIO\MutationGate\Core\Runner\Version;

it('is a release of the gate as its version spells it, a branch with its commit, or none recorded', function (): void {
    $release = GateRelease::of(Version::of('nightworksio/mutation-gate', 'v1.2.0', 'abc123'));
    $branch = GateRelease::of(Version::of('nightworksio/mutation-gate', 'dev-main', 'abc123'));

    expect($release->value())->toBe('v1.2.0')
        ->and($branch->value())->toBe('dev-main abc123')
        ->and($release->equals(GateRelease::spelt('v1.2.0')))->toBeTrue()
        ->and($release->equals($branch))->toBeFalse()
        ->and($release->isRecorded())->toBeTrue()
        ->and(GateRelease::unrecorded()->isRecorded())->toBeFalse()
        ->and(GateRelease::unrecorded()->value())->toBe('');
});

it('costs as the same release, or as any build of a branch where it is one, but never as another release or none recorded', function (string $one, string $other, bool $alike): void {
    expect(GateRelease::spelt($one)->costsAs(GateRelease::spelt($other)))->toBe($alike)
        ->and(GateRelease::spelt($other)->costsAs(GateRelease::spelt($one)))->toBe($alike);
})->with([
    'the same release' => ['v1.2.0', 'v1.2.0', true],
    'two releases' => ['v1.2.0', 'v1.2.1', false],
    'two commits of a branch' => ['dev-main abc123', 'dev-main def456', true],
    'two branches' => ['dev-main abc123', '2.x-dev def456', true],
    'a branch with no commit recorded' => ['dev-7fe21685', 'dev-main def456', true],
    'a branch and a release' => ['dev-main abc123', 'v1.2.0', false],
    'a branch and none recorded' => ['dev-main abc123', '', false],
    'none recorded twice' => ['', '', true],
]);
