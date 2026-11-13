<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Local\PowerSource;
use NightWorksIO\MutationGate\Cli\Command\PowerReads;
use NightWorksIO\MutationGate\Core\Local\Power;
use NightWorksIO\MutationGate\Core\Local\System;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\NoticesChangesFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A Linux machine whose battery discharges, or charges, under this directory. */
function powerReadsBattery(string $sys, bool $discharging): void
{
    Scratch::write($sys, 'class/power_supply/BAT0/type', "Battery\n");
    Scratch::write($sys, 'class/power_supply/BAT0/status', $discharging ? "Discharging\n" : "Charging\n");
}

it('reads the power source again only once the daemon tells of a change of it', function (): void {
    $sys = Scratch::directory();
    powerReadsBattery($sys, discharging: true);
    $notices = new NoticesChangesFake([], [0, 0, 1]);
    $reads = PowerReads::of(PowerSource::on(System::Linux, $sys), $notices, Seconds::of(2));

    $first = $reads->now();
    powerReadsBattery($sys, discharging: false);

    expect($first)->toBe(Power::Saving)
        ->and($reads->now())->toBe(Power::Saving)
        ->and($reads->now())->toBe(Power::Mains)
        ->and($notices->powers)->toEqual([[0, Seconds::none()], [0, Seconds::none()], [0, Seconds::none()]]);
});

it('waits on the daemon for a change of the power source, a wait long at most, while it answers', function (): void {
    $notices = new NoticesChangesFake([], [1, NotWritten::because('the socket is gone')]);
    $reads = PowerReads::of(PowerSource::on(System::Linux, Scratch::directory()), $notices, Seconds::of(2));

    expect($reads->waited())->toBeTrue()
        ->and($reads->waited())->toBeFalse()
        ->and($reads->waited())->toBeFalse()
        ->and($notices->powers)->toEqual([[0, Seconds::of(2)], [1, Seconds::of(2)]]);
});

it('reads the power source at each read where no daemon tells of its changes', function (): void {
    $sys = Scratch::directory();
    powerReadsBattery($sys, discharging: true);
    $reads = PowerReads::of(PowerSource::on(System::Linux, $sys), NotGiven::value(), Seconds::of(2));

    $first = $reads->now();
    powerReadsBattery($sys, discharging: false);

    expect($first)->toBe(Power::Saving)
        ->and($reads->now())->toBe(Power::Mains)
        ->and($reads->waited())->toBeFalse();
});
