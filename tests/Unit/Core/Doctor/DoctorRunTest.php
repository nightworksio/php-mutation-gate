<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Runner\Version;

it('keeps the release it pins and the command it started as, whichever it is told first', function (): void {
    $pin = GatePin::of(Version::of('nightworksio/mutation-gate', 'v0.4.0', 'abc'));
    $gate = GateCommand::byPhp(Path::of('tools/mutation-gate'));
    $run = DoctorRun::of(new DateTimeImmutable('2026-11-05'), -1);

    expect($run->pin())->toEqual(GatePin::unknown())
        ->and($run->pinning($pin)->startedAs($gate)->pin())->toBe($pin)
        ->and($run->startedAs($gate)->pinning($pin)->pin())->toBe($pin);
});
