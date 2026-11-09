<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Local\OwnPriority;
use NightWorksIO\MutationGate\Adapter\Local\PowerSource;
use NightWorksIO\MutationGate\Cli\Command\WatchLoad;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Reporting;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\Local\LocalLoop;
use NightWorksIO\MutationGate\Core\Local\OnBattery;
use NightWorksIO\MutationGate\Core\Local\Power;
use NightWorksIO\MutationGate\Core\Local\Priority;
use NightWorksIO\MutationGate\Core\Local\System;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Watch\Watched;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A machine on Linux whose battery discharges, or charges, its supplies under this directory. */
$battery = static function (string $sys, bool $discharging): void {
    Scratch::write($sys, 'class/power_supply/BAT0/type', "Battery\n");
    Scratch::write($sys, 'class/power_supply/BAT0/status', $discharging ? "Discharging\n" : "Charging\n");
};

/** A load of eight cores at normal priority, as these settings say, reading the battery under this directory. */
$load = static fn(LocalWork $work, string $sys): WatchLoad => WatchLoad::on(
    $work,
    PowerSource::on(System::Linux, $sys),
    OwnPriority::on(System::Other, 0),
    ProcessCount::of(8),
);

$composed = static fn(): Composed => new Composed(
    Flows::settings(),
    Flows::adapters(Flows::project()),
    Flows::setup(),
    new Reporting(new Chosen(new FirstParty()->extend(Extensions::of(Origin::of(ThisPackage::COMPOSER)))), Variables::of([])),
);

it('runs a round on the workers the settings give, as the local loop, and on one where it reduces on battery', function () use ($battery, $load, $composed): void {
    $sys = Scratch::directory();
    $seen = Watched::in(Flows::trees());
    $work = LocalWork::of(workers: 3, priority: Priority::Normal);
    $battery($sys, discharging: false);
    $onPower = $load($work, $sys)->fitted($composed(), Power::Mains, $seen);
    $onBattery = $load($work, $sys)->fitted($composed(), Power::Saving, $seen);
    $running = $load(LocalWork::of(workers: 3, priority: Priority::Normal, onBattery: OnBattery::Run), $sys)->fitted($composed(), Power::Saving, $seen);

    expect([$onPower->adapters->cores->count(), $onBattery->adapters->cores->count(), $running->adapters->cores->count()])->toBe([3, 1, 3])
        ->and($onPower->adapters->loop)->toEqual(LocalLoop::of($work));
});

it('holds a round on battery where it pauses, says so once, and runs it once the machine is on power', function () use ($battery, $load): void {
    $sys = Scratch::directory();
    $battery($sys, discharging: true);
    $said = [];
    $waits = 0;
    $paused = $load(LocalWork::of(priority: Priority::Normal, onBattery: OnBattery::Pause), $sys);

    $power = $paused->powered(
        static function () use ($battery, $sys, &$waits): bool {
            ++$waits;
            $battery($sys, discharging: $waits < 2);

            return true;
        },
        static function (string $text) use (&$said): void {
            $said[] = $text;
        },
    );

    expect($power)->toBe(Power::Mains)
        ->and($waits)->toBe(2)
        ->and($said)->toBe(['On battery or saving power, so watch holds its next round until power returns.']);
});

it('stops holding where the wait says to stop, and holds nothing where it reduces or runs', function (OnBattery $onBattery, Power|NotGiven $power) use ($battery, $load): void {
    $sys = Scratch::directory();
    $battery($sys, discharging: true);

    expect($load(LocalWork::of(priority: Priority::Normal, onBattery: $onBattery), $sys)->powered(static fn(): bool => false, static function (): void {
    }))->toEqual($power);
})->with([
    'pause' => [OnBattery::Pause, fn(): NotGiven => NotGiven::value()],
    'reduce' => [OnBattery::Reduce, Power::Saving],
    'run' => [OnBattery::Run, Power::Mains],
]);
