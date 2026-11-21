<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\Signal;
use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;
use NightWorksIO\MutationGate\Tests\Support\Listed;

/**
 * What a stop sent and listed, in order: each signal it sent, written as the `kill` command that sends that one
 * signal, and `listing` where it took a listing, which answers these listings in turn and the last of them from then
 * on. The caller spares itself (20), its parent (10) and the system's first, their groups and session 10, unless the
 * stop lists what it spares itself.
 *
 * @param  list<string>                              $listings
 * @param  Closure(Listed, SparedProcesses): void $stop
 * @return list<string>
 */
function processTableStopped(array $listings, Closure $stop): array
{
    $ran = [];
    $sent = static function (int $target, Signal $signal) use (&$ran): bool {
        $ran[] = sprintf('kill -%s %s', $signal->value, $target < 0 ? sprintf('-- %d', $target) : $target);

        return true;
    };
    $listed = new Listed(static function () use (&$listings, &$ran): ProcessListing {
        $ran[] = 'listing';

        return Listed::of(count($listings) > 1 ? array_shift($listings) : $listings[0] ?? '');
    }, $sent);
    $stop($listed, SparedProcesses::of(Listed::of("10 1\n20 10\n"), 20, 10, Listed::always('')->sendingTo($sent)));

    return $ran;
}

/**
 * Each `kill` command that sends one of these processes the signal.
 *
 * @param  list<int>    $pids
 * @return list<string>
 */
function processTableSent(Signal $signal, array $pids): array
{
    return array_map(static fn(int $pid): string => sprintf('kill -%s %d', $signal->value, $pid), $pids);
}

it('freezes a process, then each process a listing finds under it, until a listing finds no new one, and only then kills them all, deepest first', function (): void {
    $ran = processTableStopped(["100 1\n101 100\n", "100 1\n101 100\n102 101\n"], static function (Listed $listed, SparedProcesses $spared): void {
        ProcessTable::stop(100, $listed, spared: $spared);
    });

    expect($ran)->toBe(['kill -STOP 100', 'listing', 'kill -STOP 101', 'listing', 'kill -STOP 102', 'listing', 'kill -KILL 102', 'kill -KILL 101', 'kill -KILL 100']);
});

it('ends a stop whose tree keeps growing after its most listings, and kills every process it found', function (): void {
    $listings = [];

    for ($grown = 1; $grown <= ProcessTable::LISTINGS + 5; $grown++) {
        $listings[] = implode("\n", array_map(static fn(int $child): string => sprintf('%d 100', 100 + $child), range(1, $grown)));
    }

    $ran = processTableStopped($listings, static function (Listed $listed, SparedProcesses $spared): void {
        ProcessTable::stop(100, $listed, leads: true, spared: $spared);
    });

    expect(ProcessTable::LISTINGS)->toBe(32)
        ->and(array_count_values($ran)['listing'])->toBe(ProcessTable::LISTINGS)
        ->and(array_slice($ran, -(ProcessTable::LISTINGS + 2)))->toBe([
            ...processTableSent(Signal::Kill, [...range(101, 100 + ProcessTable::LISTINGS), 100]),
            'kill -KILL -- -100',
        ]);
});

it('stops at its last listing where that one finds no new process', function (): void {
    $listings = [];

    for ($grown = 1; $grown < ProcessTable::LISTINGS; $grown++) {
        $listings[] = implode("\n", array_map(static fn(int $child): string => sprintf('%d 100', 100 + $child), range(1, $grown)));
    }

    $ran = processTableStopped($listings, static function (Listed $listed, SparedProcesses $spared): void {
        ProcessTable::stop(100, $listed, spared: $spared);
    });

    expect(array_count_values($ran)['listing'])->toBe(ProcessTable::LISTINGS)
        ->and(array_slice($ran, -(ProcessTable::LISTINGS + 2)))->toBe([
            sprintf('kill -STOP %d', 100 + ProcessTable::LISTINGS - 1),
            'listing',
            ...processTableSent(Signal::Kill, [...range(101, 100 + ProcessTable::LISTINGS - 1), 100]),
        ]);
});

it('stops nothing by an id that names no one process: init, the caller\'s process group, or every process', function (int $pid): void {
    expect(processTableStopped([''], static function (Listed $listed, SparedProcesses $spared) use ($pid): void {
        ProcessTable::stop($pid, $listed, spared: $spared);
    }))->toBe([]);
})->with([1, 0, -1]);

it('stops the lowest id that names one process', function (): void {
    expect(processTableStopped([''], static function (Listed $listed, SparedProcesses $spared): void {
        ProcessTable::stop(2, $listed, spared: $spared);
    }))->toBe(['kill -STOP 2', 'listing', 'kill -KILL 2']);
});

it('leaves every id that names no one process, and every one the caller spares, out of what it kills', function (): void {
    expect(processTableStopped([''], static function (Listed $listed, SparedProcesses $spared): void {
        ProcessTable::stopHeld(101, [1, 0, 2, -1, 10, 20], leads: false, spared: $spared);
    }))->toBe(['kill -KILL 2', 'kill -KILL 101']);
});

it('kills, after the tree of a process that leads its session, the whole session at once', function (): void {
    expect(processTableStopped(["100 1\n101 100\n"], static function (Listed $listed, SparedProcesses $spared): void {
        ProcessTable::stop(100, $listed, leads: true, spared: $spared);
    }))->toBe(['kill -STOP 100', 'listing', 'kill -STOP 101', 'listing', 'kill -KILL 101', 'kill -KILL 100', 'kill -KILL -- -100']);
});

it('lists what the caller spares before it signals anything, where it was handed none, and signals by what it lists', function (): void {
    $ran = processTableStopped([''], static function (Listed $listed): void {
        ProcessTable::stop(2_147_483_646, $listed);
    });

    expect($ran)->toBe(['listing', 'kill -STOP 2147483646', 'listing', 'kill -KILL 2147483646']);
});

it('never stops the caller, a process above it, nor one of them found under the process it stops', function (int $pid): void {
    expect(processTableStopped(["100 1\n10 100\n101 100\n"], static function (Listed $listed, SparedProcesses $spared) use ($pid): void {
        ProcessTable::stop($pid, $listed, spared: $spared);
    }))->toBe($pid === 100 ? ['kill -STOP 100', 'listing', 'kill -STOP 101', 'listing', 'kill -KILL 101', 'kill -KILL 100'] : []);
})->with([10, 20, 100]);

it('kills every process a cgroup holds, the process last, and then its session, without a listing', function (bool $leads): void {
    $ran = processTableStopped([''], static function (Listed $listed, SparedProcesses $spared) use ($leads): void {
        ProcessTable::stopHeld(100, [100, 300, 101, 10], leads: $leads, spared: $spared);
    });
    $killed = ['kill -KILL 300', 'kill -KILL 101', 'kill -KILL 100'];

    expect($ran)->toBe($leads ? [...$killed, 'kill -KILL -- -100'] : $killed);
})->with(['a session leader' => [true], 'a process' => [false]]);

it('kills the process a cgroup was made for where the cgroup holds nothing', function (): void {
    expect(processTableStopped([''], static function (Listed $listed, SparedProcesses $spared): void {
        ProcessTable::stopHeld(100, [], leads: false, spared: $spared);
    }))->toBe(['kill -KILL 100']);
});

it('stops nothing a cgroup holds where the process it was made for is one the caller spares', function (int $pid): void {
    expect(processTableStopped([''], static function (Listed $listed, SparedProcesses $spared) use ($pid): void {
        ProcessTable::stopHeld($pid, [300], leads: true, spared: $spared);
    }))->toBe([]);
})->with([10, 20, 1]);

it('never kills the group or the session of the caller, nor the group a process above it leads', function (int $leader): void {
    expect(processTableStopped([''], static function (Listed $listed, SparedProcesses $spared) use ($leader): void {
        ProcessTable::end($leader, $spared);
    }))->toBe($leader === 30 ? ['kill -KILL -- -30'] : []);
})->with([10, 20, 1, 0, -1, 30]);
