<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\PrintedDeclarations;
use NightWorksIO\MutationGate\Adapter\Outbreak\ShippedExtension;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\ToldExtension;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LineRecording;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShippedOutbreak;

afterEach(function (): void {
    Scratch::sweep();
});

$extension = (static fn(string $outbreak, string $directory): ShippedExtension|NotGiven => ShippedExtension::beneath(Configs::options(sprintf('{"outbreak": %s}', $outbreak)), $directory, new PrintedDeclarations()));

it('tells a stream to load the extension, what it watches and the steps\' floor, recording lines only for a quick round\'s map', function () use ($extension): void {
    $directory = ShippedOutbreak::withExtension();
    $loaded = $extension('{"stepFloor": 500}', $directory);

    expect(ToldExtension::of($loaded, LineRecording::forTheMap('/recorded')))->toBe([
        GateVariable::StreamExtension->value => ShippedOutbreak::extensionIn($directory),
        GateVariable::Observe->value => 'touches,lines,steps',
        GateVariable::StepFloor->value => '500',
    ])
        ->and(ToldExtension::of($loaded, LineRecording::standingIn('/recorded')))->toBe([
            GateVariable::StreamExtension->value => ShippedOutbreak::extensionIn($directory),
            GateVariable::Observe->value => 'touches,steps',
            GateVariable::StepFloor->value => '500',
        ])
        ->and(ToldExtension::of($extension('{"steps": false}', $directory), LineRecording::none()))->toBe([
            GateVariable::StreamExtension->value => ShippedOutbreak::extensionIn($directory),
            GateVariable::Observe->value => 'touches',
        ])
        ->and(ToldExtension::of(NotGiven::value(), LineRecording::forTheMap('/recorded')))->toBe([]);
})->skip(PHP_ZTS === 1, 'no extension ships for a thread-safe PHP');

it('records a stream\'s lines with pcov where they stand in for the coverage, or are kept for the map with no extension recording them', function () use ($extension): void {
    $directory = ShippedOutbreak::withExtension();
    $lines = $extension('{}', $directory);
    $noLines = $extension('{"lines": false}', $directory);

    expect([
        ToldExtension::recordsWithPcov($lines, LineRecording::standingIn('/recorded')),
        ToldExtension::recordsWithPcov($lines, LineRecording::forTheMap('/recorded')),
        ToldExtension::recordsWithPcov($noLines, LineRecording::forTheMap('/recorded')),
        ToldExtension::recordsWithPcov(NotGiven::value(), LineRecording::forTheMap('/recorded')),
        ToldExtension::recordsWithPcov(NotGiven::value(), LineRecording::none()),
    ])->toBe([true, false, true, true, false]);
})->skip(PHP_ZTS === 1, 'no extension ships for a thread-safe PHP');
