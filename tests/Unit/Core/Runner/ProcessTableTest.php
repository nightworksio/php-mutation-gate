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
