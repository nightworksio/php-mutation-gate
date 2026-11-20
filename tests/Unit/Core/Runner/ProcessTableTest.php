<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;

function processTableSpared(): SparedProcesses
{
    return SparedProcesses::of("  10   1  10\n  20  10  20\n", 20, 10);
}

it('finds every process under one, at any depth, each after the processes under it', function (): void {
    $table = ProcessTable::parse("    1     0\n  100     1\n  101   100\n  102   101\n  103   100\n  200     1\nnot a row\n");

    expect($table->descendantsOf(100))->toBe([102, 101, 103])
        ->and($table->descendantsOf(102))->toBe([])
        ->and($table->descendantsOf(999))->toBe([]);
});

it('never counts a process under itself', function (): void {
    expect(ProcessTable::parse("0 0\n5 0\n")->descendantsOf(0))->toBe([5]);
});

it('stops processes at once, by their ids', function (): void {
    expect(ProcessTable::killing(processTableSpared(), 101, 102))->toBe(['kill', '-KILL', '101', '102'])
        ->and(ProcessTable::LISTING)->toBe(['ps', '-A', '-o', 'pid=', '-o', 'ppid=']);
});

it('freezes a process, then each process a listing finds under it, until a listing finds no new one, and only then kills them all, deepest first', function (): void {
    $listings = ["100 1\n101 100\n", "100 1\n101 100\n102 101\n", "100 1\n101 100\n102 101\n"];
    $ran = [];

    ProcessTable::stop(100, static function (array $command) use (&$listings, &$ran): string {
        $ran[] = implode(' ', $command);

        return $command === ProcessTable::LISTING ? (string) array_shift($listings) : '';
    }, spared: processTableSpared());

    expect($ran)->toBe([
        'kill -STOP 100',
        'ps -A -o pid= -o ppid=',
        'kill -STOP 101',
        'ps -A -o pid= -o ppid=',
        'kill -STOP 102',
        'ps -A -o pid= -o ppid=',
        'kill -KILL 102 101 100',
    ]);
});

it('stops nothing by an id that names no one process: init, the caller\'s process group, or every process', function (int $pid): void {
    $ran = [];

    ProcessTable::stop($pid, static function (array $command) use (&$ran): string {
        $ran[] = implode(' ', $command);

        return '';
    }, spared: processTableSpared());

    expect($ran)->toBe([]);
})->with([1, 0, -1]);

it('stops the lowest id that names one process', function (): void {
    $ran = [];

    ProcessTable::stop(2, static function (array $command) use (&$ran): string {
        $ran[] = implode(' ', $command);

        return '';
    }, spared: processTableSpared());

    expect($ran)->toBe(['kill -STOP 2', 'ps -A -o pid= -o ppid=', 'kill -KILL 2']);
});

it('leaves every id that names no one process out of what it signals', function (): void {
    expect(ProcessTable::killing(processTableSpared(), 1, 101, 0, 2, -1))->toBe(['kill', '-KILL', '101', '2']);
});

it('kills, after the tree of a process that leads its session, the whole session at once', function (): void {
    $ran = [];

    ProcessTable::stop(100, static function (array $command) use (&$ran): string {
        $ran[] = implode(' ', $command);

        return $command === ProcessTable::LISTING ? "100 1\n101 100\n" : '';
    }, leads: true, spared: processTableSpared());

    expect($ran)->toBe([
        'kill -STOP 100',
        'ps -A -o pid= -o ppid=',
        'kill -STOP 101',
        'ps -A -o pid= -o ppid=',
        'kill -KILL 101 100',
        'kill -KILL -- -100',
    ]);
});

it('kills every process left of the session a process led, and none by an id that names no one process', function (): void {
    expect(ProcessTable::ending(processTableSpared(), 100))->toBe(['kill', '-KILL', '--', '-100'])
        ->and(ProcessTable::ending(processTableSpared(), 2))->toBe(['kill', '-KILL', '--', '-2'])
        ->and(ProcessTable::ending(processTableSpared(), 1))->toBe(['kill', '-KILL'])
        ->and(ProcessTable::ending(processTableSpared(), 0))->toBe(['kill', '-KILL']);
});

it('lists what the caller spares before it signals anything, where it was handed none', function (): void {
    $ran = [];

    ProcessTable::stop(100, static function (array $command) use (&$ran): string {
        $ran[] = implode(' ', $command);

        return '';
    });

    expect($ran)->toBe(['ps -A -o pid= -o ppid= -opgid=', 'kill -STOP 100', 'ps -A -o pid= -o ppid=', 'kill -KILL 100']);
});

it('never stops the caller, a process above it, nor one of them found under the process it stops', function (int $pid): void {
    $ran = [];

    ProcessTable::stop($pid, static function (array $command) use (&$ran): string {
        $ran[] = implode(' ', $command);

        return $command === ProcessTable::LISTING ? "100 1\n10 100\n101 100\n" : '';
    }, spared: processTableSpared());

    expect($ran)->toBe($pid === 100 ? ['kill -STOP 100', 'ps -A -o pid= -o ppid=', 'kill -STOP 101', 'ps -A -o pid= -o ppid=', 'kill -KILL 101 100'] : []);
})->with([10, 20, 100]);

it('never kills the group or the session of the caller, nor the group a process above it leads', function (int $leader): void {
    $ran = [];

    ProcessTable::end($leader, static function (array $command) use (&$ran): string {
        $ran[] = implode(' ', $command);

        return '';
    }, processTableSpared());

    expect($ran)->toBe($leader === 30 ? ['kill -KILL -- -30'] : [])
        ->and(ProcessTable::ending(processTableSpared(), $leader))->toBe($leader === 30 ? ['kill', '-KILL', '--', '-30'] : ['kill', '-KILL']);
})->with([10, 20, 1, 0, -1, 30]);
