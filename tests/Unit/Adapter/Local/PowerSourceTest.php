<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Local\Launch;
use NightWorksIO\MutationGate\Adapter\Local\PowerSource;
use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\Local\CpuProfile;
use NightWorksIO\MutationGate\Core\Local\Power;
use NightWorksIO\MutationGate\Core\Local\System;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads macOS on battery, or in Low Power Mode, as saving power', function (string $source, string $settings, Power $power): void {
    expect(PowerSource::macos($source, $settings))->toBe($power);
})->with([
    'on power' => ["Now drawing from 'AC Power'\n", " lowpowermode         0\n", Power::Mains],
    'on battery' => ["Now drawing from 'Battery Power'\n -InternalBattery-0\n", " lowpowermode         0\n", Power::Saving],
    'in Low Power Mode' => ["Now drawing from 'AC Power'\n", "System-wide power settings:\n lowpowermode         1\n", Power::Saving],
    'nothing read' => ['', '', Power::Mains],
]);

it('reads Windows\' battery discharging as saving power', function (string $status, Power $power): void {
    expect(PowerSource::windows($status))->toBe($power);
})->with([
    'discharging' => ["1\r\n", Power::Saving],
    'on power' => ["2\r\n", Power::Mains],
    'no battery' => ['', Power::Mains],
]);

it('reads Linux\' supplies and platform profile', function (Power $power, string ...$files): void {
    $sys = Scratch::directory();

    foreach (array_chunk($files, 2) as [$path, $contents]) {
        Scratch::write($sys, $path, $contents);
    }

    expect(PowerSource::on(System::Linux, $sys)->now())->toBe($power);
})->with([
    'a battery that discharges' => [Power::Saving, 'class/power_supply/BAT0/type', "Battery\n", 'class/power_supply/BAT0/status', "Discharging\n"],
    'a battery that charges' => [Power::Mains, 'class/power_supply/BAT0/type', "Battery\n", 'class/power_supply/BAT0/status', "Charging\n", 'class/power_supply/AC/type', "Mains\n"],
    'a low-power profile' => [Power::Saving, 'firmware/acpi/platform_profile', "low-power\n"],
    'no supply at all' => [Power::Mains],
]);

it('reads another system as on power', function (): void {
    expect(PowerSource::on(System::Other, Scratch::directory())->now())->toBe(Power::Mains);
});

it('starts the daemon under the class: a QoS clamp on macOS, a niceness on Linux, nothing at normal or elsewhere', function (): void {
    $quiet = LocalWork::of(CpuProfile::Quiet);
    $fast = LocalWork::of(CpuProfile::Fast);
    $taskpolicy = is_executable(Launch::TASKPOLICY);
    $nice = is_executable('/usr/bin/nice');

    expect(Launch::on(System::Darwin)->prefix($quiet))->toBe($taskpolicy ? [Launch::TASKPOLICY, '-c', 'background'] : [])
        ->and(Launch::on(System::Linux)->prefix(LocalWork::of()))->toBe($nice ? ['/usr/bin/nice', '-n', '10'] : [])
        ->and(Launch::on(System::Darwin)->prefix($fast))->toBe([])
        ->and(Launch::on(System::Linux)->prefix($fast))->toBe([])
        ->and(Launch::on(System::Windows)->prefix($quiet))->toBe([]);
});
