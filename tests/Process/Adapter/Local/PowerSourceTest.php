<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Local\PowerSource;
use NightWorksIO\MutationGate\Core\Local\Power;
use NightWorksIO\MutationGate\Core\Local\System;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = ['holds:src/Adapter/Local/PowerSource.php'];

afterEach(function (): void {
    Scratch::sweep();
});

it('reads Windows\' battery from what the PowerShell on the path prints, as mains where it fails or none is there', function (string $script, Power $power): void {
    $bin = Scratch::directory();

    if ($script !== '') {
        Scratch::write($bin, 'powershell', $script);
        chmod(sprintf('%s/powershell', $bin), 0o755);
    }

    $read = Environment::during(
        ['PATH' => $bin],
        static fn(): ArrayObject => new ArrayObject([PowerSource::on(System::Windows, Scratch::directory())->now()]),
    );

    expect($read[0])->toBe($power);
})->with([
    'discharging' => ["#!/bin/sh\necho 1\n", Power::Saving],
    'on power' => ["#!/bin/sh\necho 2\n", Power::Mains],
    'failing' => ["#!/bin/sh\necho 1\nexit 1\n", Power::Mains],
    'none on the path' => ['', Power::Mains],
])->group(...$holds);

it('reads this machine\'s power source as its system says', function (): void {
    expect(PowerSource::here()->now())->toBe(PowerSource::on(System::here(), '/sys')->now());
})->group(...$holds);

it('reads what a program printed, and nothing where it failed or is not there', function (): void {
    expect(PowerSource::printedBy(['sh', '-c', 'printf "AC Power"']))->toBe('AC Power')
        ->and(PowerSource::printedBy(['sh', '-c', 'printf half; exit 1']))->toBe('')
        ->and(PowerSource::printedBy(['mutation-gate-no-such-program']))->toBe('');
})->group(...$holds);
