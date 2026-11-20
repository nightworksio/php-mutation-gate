<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;
use NightWorksIO\MutationGate\Tests\Support\Listed;

const SPARED_LISTING = "    1     0     1\n  500     1   500\n  600   500   500\n  700   600   700\n  800   700   700\n  900   800   900\n  950   500   950\n";

it('spares the caller and every process above it, and signals any other process', function (int $pid, bool $signals): void {
    expect(SparedProcesses::of(Listed::of(SPARED_LISTING), 800, 500)->signals($pid))->toBe($signals);
})->with([
    'itself' => [800, false],
    'its parent' => [700, false],
    'its grandparent' => [600, false],
    'the process at the top of its line' => [500, false],
    'the system\'s first process' => [1, false],
    'its own group as an id' => [0, false],
    'every process' => [-1, false],
    'a process it started' => [900, true],
    'a process beside it' => [950, true],
    'the lowest id that names one process' => [2, true],
]);

it('spares the groups of the caller and of every process above it, and its session, and signals any other group', function (int $leader, bool $signals): void {
    expect(SparedProcesses::of(Listed::of(SPARED_LISTING), 800, 450)->signalsGroup($leader))->toBe($signals);
})->with([
    'its own group' => [700, false],
    'its session' => [450, false],
    'a group a process above it leads' => [500, false],
    'itself' => [800, false],
    'the system\'s first process' => [1, false],
    'the caller\'s group as an id' => [0, false],
    'every process' => [-1, false],
    'a group a process it started leads' => [900, true],
    'a group beside it' => [950, true],
]);

it('spares the caller alone where the listing does not hold it', function (): void {
    $spared = SparedProcesses::of(Listed::of("  10   1  10\n"), 800, 0);

    expect($spared->signals(800))->toBeFalse()
        ->and($spared->signals(10))->toBeTrue()
        ->and($spared->signalsGroup(10))->toBeTrue()
        ->and($spared->signalsGroup(800))->toBeFalse();
});

it('ends its walk up where a parent link loops back', function (): void {
    $spared = SparedProcesses::of(Listed::of("  10  20  10\n  20  10  20\n"), 10, 0);

    expect($spared->signals(10))->toBeFalse()
        ->and($spared->signals(20))->toBeFalse()
        ->and($spared->signals(30))->toBeTrue();
});

it('takes one listing of the processes, and spares this process', function (): void {
    $listed = 0;
    $spared = SparedProcesses::listed(new Listed(static function () use (&$listed): ProcessListing {
        $listed++;

        return ProcessListing::printed('');
    }));

    expect($listed)->toBe(1)
        ->and($spared->signals((int) getmypid()))->toBeFalse()
        ->and($spared->signalsGroup((int) posix_getsid(0)))->toBeFalse();
});

it('takes what it was told where it was told to this process, without a listing', function (): void {
    $told = SparedProcesses::of(Listed::of(sprintf("  10   1  10\n %d  10  10\n", getmypid())), (int) getmypid(), 77)->told();
    $listed = 0;
    $spared = SparedProcesses::toldOr($told, new Listed(static function () use (&$listed): ProcessListing {
        $listed++;

        return ProcessListing::printed('');
    }));

    expect($listed)->toBe(0)
        ->and($told)->toBe(sprintf('%1$d;%1$d,10,1;%1$d,10,1,77', getmypid()))
        ->and($spared->signals(10))->toBeFalse()
        ->and($spared->signals((int) getmypid()))->toBeFalse()
        ->and($spared->signalsGroup(77))->toBeFalse()
        ->and($spared->signals(11))->toBeTrue();
});

it('lists anew where what it was told was told to another process, or is not whole', function (string $told): void {
    $listed = 0;
    $spared = SparedProcesses::toldOr($told, new Listed(static function () use (&$listed): ProcessListing {
        $listed++;

        return Listed::of("  10   1  10\n");
    }));

    expect($listed)->toBe(1)
        ->and($spared->signals(10))->toBeTrue()
        ->and($spared->signals((int) getmypid()))->toBeFalse();
})->with([
    'another process' => ['1;10;10'],
    'nothing' => [''],
]);

it('lists anew where what it was told to this process is not whole', function (): void {
    $listed = 0;
    SparedProcesses::toldOr(sprintf('%d;10', getmypid()), new Listed(static function () use (&$listed): ProcessListing {
        $listed++;

        return ProcessListing::printed('');
    }));

    expect($listed)->toBe(1);
});
