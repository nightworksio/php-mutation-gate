<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\Lineage;
use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;

$holds = [
    'holds:src/Adapter/Process/Lineage.php',
];

afterEach(function (): void {
    putenv(SparedProcesses::VARIABLE);
});

it('tells this process what it spares, which it then takes without listing again', function (): void {
    $told = Lineage::told();
    $spared = Lineage::spared();

    expect(getenv(SparedProcesses::VARIABLE))->toBe($told->told())
        ->and($spared->told())->toBe($told->told())
        ->and($spared->signals((int) getmypid()))->toBeFalse()
        ->and($spared->signals(posix_getppid()))->toBeFalse()
        ->and($spared->signalsGroup(posix_getpgrp()))->toBeFalse()
        ->and($spared->signalsGroup((int) posix_getsid(0)))->toBeFalse();
})->group(...$holds);

it('lists what it spares where nothing was told to this process', function (): void {
    putenv(sprintf('%s=1;1;1', SparedProcesses::VARIABLE));
    $spared = Lineage::spared();

    expect($spared->signals((int) getmypid()))->toBeFalse()
        ->and($spared->signals(posix_getppid()))->toBeFalse()
        ->and($spared->signals(1))->toBeFalse();
})->group(...$holds);
