<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessTable;

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
    expect(ProcessTable::killing(101, 102))->toBe(['kill', '-KILL', '101', '102'])
        ->and(ProcessTable::LISTING)->toBe(['ps', '-A', '-o', 'pid=', '-o', 'ppid=']);
});

it('freezes a process, then each process a listing finds under it, until a listing finds no new one, and only then kills them all, deepest first', function (): void {
    $listings = ["100 1\n101 100\n", "100 1\n101 100\n102 101\n", "100 1\n101 100\n102 101\n"];
    $ran = [];

    ProcessTable::stop(100, static function (array $command) use (&$listings, &$ran): string {
        $ran[] = implode(' ', $command);

        return $command === ProcessTable::LISTING ? (string) array_shift($listings) : '';
    });

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
    });

    expect($ran)->toBe([]);
})->with([1, 0, -1]);

it('stops the lowest id that names one process', function (): void {
    $ran = [];

    ProcessTable::stop(2, static function (array $command) use (&$ran): string {
        $ran[] = implode(' ', $command);

        return '';
    });

    expect($ran)->toBe(['kill -STOP 2', 'ps -A -o pid= -o ppid=', 'kill -KILL 2']);
});

it('leaves every id that names no one process out of what it signals', function (): void {
    expect(ProcessTable::killing(1, 101, 0, 2, -1))->toBe(['kill', '-KILL', '101', '2']);
});
