<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Ran;

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
