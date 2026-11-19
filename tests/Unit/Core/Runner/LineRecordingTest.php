<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\LineRecording;

it('records into a directory for the kept map without standing in for the suite\'s coverage report', function (): void {
    $recording = LineRecording::forTheMap('/tmp/lines');

    expect($recording->directory())->toBe('/tmp/lines')
        ->and($recording->records())->toBeTrue()
        ->and($recording->standsIn())->toBeFalse();
});

it('stands in for the suite\'s coverage report only where it records', function (): void {
    expect(LineRecording::standingIn('/tmp/lines')->standsIn())->toBeTrue()
        ->and(LineRecording::standingIn('')->standsIn())->toBeFalse()
        ->and(LineRecording::none()->records())->toBeFalse()
        ->and(LineRecording::none()->directory())->toBe('');
});
