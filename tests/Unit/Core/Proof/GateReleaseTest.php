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
