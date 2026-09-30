<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('is a program that finished, and whether it succeeded', function (): void {
    $passed = Ran::finished(succeeded: true, output: 'OK');
    $failed = Ran::finished(succeeded: false, output: 'FAILED');

    expect($passed->succeeded())->toBeTrue()
        ->and($passed->wasStopped())->toBeFalse()
        ->and($passed->output())->toBe('OK')
        ->and($failed->succeeded())->toBeFalse()
        ->and($failed->wasStopped())->toBeFalse()
        ->and($failed->output())->toBe('FAILED');
});

it('is a program stopped at its deadline, which did not succeed', function (): void {
    $stopped = Ran::stopped('half');

    expect($stopped->wasStopped())->toBeTrue()
        ->and($stopped->succeeded())->toBeFalse()
        ->and($stopped->output())->toBe('half');
});

it('ran no time at all until it is told how long it ran', function (): void {
    expect(Ran::finished(succeeded: true, output: '')->took())->toEqual(Seconds::of(0.0))
        ->and(Ran::stopped('')->took())->toEqual(Seconds::of(0.0))
        ->and(Ran::stopped('half')->taking(Seconds::of(2.0))->took())->toEqual(Seconds::of(2.0))
        ->and(Ran::stopped('half')->taking(Seconds::of(2.0))->wasStopped())->toBeTrue();
});
