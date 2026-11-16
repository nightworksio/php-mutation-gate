<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\ProgressReport;
use NightWorksIO\MutationGate\Core\Runner\Remaining;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\GaugeFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('says how long the shard ran, what the machine has left, the processes that hold the most memory and the gate\'s last lines', function (): void {
    $said = sprintf('%s/said', Scratch::directory());
    $lines = [];

    for ($line = 1; $line <= 32; $line++) {
        $lines[] = sprintf('line %d', $line);
    }

    file_put_contents($said, sprintf("%s\n", implode("\n", $lines)));
    $report = ProgressReport::of(5, new GaugeFake(Remaining::unknown()->withMemory(2 * 1024 ** 3, 16 * 1024 ** 3)), $said);

    expect($report->at(Seconds::of(125), "  812     1 2097152 01:02:03 php\n  813   812 512 00:04 sleep\n"))->toBe(sprintf(<<<'TEXT'
        mutation-gate: shard 5, 2m after it started
        machine: memory 2.1 of 17.2 GB free
        processes: 2, the largest:
          2048 MiB  01:02:03  pid 812 of 1  php
          0 MiB  00:04  pid 813 of 812  sleep
        the gate's last lines:
        %s

        TEXT, implode("\n", array_map(static fn(string $line): string => sprintf('  %s', $line), array_slice($lines, 2)))));
});

it('says the machine is not known where its gauge reads nothing, and no line where the gate kept none', function (): void {
    $report = ProgressReport::of(1, GaugeFake::silent(), sprintf('%s/none', Scratch::directory()));

    expect($report->at(Seconds::of(0), ''))->toBe(<<<'TEXT'
        mutation-gate: shard 1, 0s after it started
        machine: not known
        processes: 0, the largest:

        the gate's last lines:


        TEXT);
});
