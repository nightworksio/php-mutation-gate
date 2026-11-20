<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;
use NightWorksIO\MutationGate\Tests\Support\Listed;

function processTableSpared(): SparedProcesses
{
    return SparedProcesses::of(Listed::of("10 1\n20 10\n"), 20, 10);
}

/**
 * What a stop ran and listed, in order: each command it ran, and `listing` where it took a listing, which answers
 * these listings in turn and the last of them from then on.
 *
 * @param  list<string>           $listings
 * @param  Closure(Closure(list<string>): string, Listed): void $stop
 * @return list<string>
 */
function processTableStopped(array $listings, Closure $stop): array
{
    $ran = [];
    $stop(static function (array $command) use (&$ran): string {
        $ran[] = implode(' ', $command);

        return '';
    }, new Listed(static function () use (&$listings, &$ran): ProcessListing {
        $ran[] = 'listing';

        return Listed::of(count($listings) > 1 ? array_shift($listings) : $listings[0] ?? '');
    }));

    return $ran;
}

it('stops processes at once, by their ids', function (): void {
    expect(ProcessTable::killing(processTableSpared(), 101, 102))->toBe(['kill', '-KILL', '101', '102']);
});

it('freezes a process, then each process a listing finds under it, until a listing finds no new one, and only then kills them all, deepest first', function (): void {
    $ran = processTableStopped(["100 1\n101 100\n", "100 1\n101 100\n102 101\n"], static function (Closure $run, Listed $listed): void {
        ProcessTable::stop(100, $run, $listed, spared: processTableSpared());
    });

    expect($ran)->toBe([
        'kill -STOP 100',
        'listing',
        'kill -STOP 101',
        'listing',
        'kill -STOP 102',
        'listing',
        'kill -KILL 102 101 100',
    ]);
});

it('ends a stop whose tree keeps growing after its most listings, and kills every process it found', function (): void {
    $listings = [];

    for ($grown = 1; $grown <= ProcessTable::LISTINGS + 5; $grown++) {
        $listings[] = implode("\n", array_map(static fn(int $child): string => sprintf('%d 100', 100 + $child), range(1, $grown)));
    }

    $ran = processTableStopped($listings, static function (Closure $run, Listed $listed): void {
        ProcessTable::stop(100, $run, $listed, leads: true, spared: processTableSpared());
    });
    $found = array_map(static fn(int $child): string => (string) (100 + $child), range(1, ProcessTable::LISTINGS));

    expect(ProcessTable::LISTINGS)->toBe(32)
        ->and(array_count_values($ran)['listing'])->toBe(ProcessTable::LISTINGS)
        ->and(array_slice($ran, -2))->toBe([sprintf('kill -KILL %s 100', implode(' ', $found)), 'kill -KILL -- -100']);
});

it('stops at its last listing where that one finds no new process', function (): void {
    $listings = [];

    for ($grown = 1; $grown < ProcessTable::LISTINGS; $grown++) {
        $listings[] = implode("\n", array_map(static fn(int $child): string => sprintf('%d 100', 100 + $child), range(1, $grown)));
    }

    $ran = processTableStopped($listings, static function (Closure $run, Listed $listed): void {
        ProcessTable::stop(100, $run, $listed, spared: processTableSpared());
    });

    expect(array_count_values($ran)['listing'])->toBe(ProcessTable::LISTINGS)
        ->and(array_slice($ran, -3))->toBe([
            sprintf('kill -STOP %d', 100 + ProcessTable::LISTINGS - 1),
            'listing',
            sprintf('kill -KILL %s 100', implode(' ', range(101, 100 + ProcessTable::LISTINGS - 1))),
        ]);
});

it('stops nothing by an id that names no one process: init, the caller\'s process group, or every process', function (int $pid): void {
    expect(processTableStopped([''], static function (Closure $run, Listed $listed) use ($pid): void {
        ProcessTable::stop($pid, $run, $listed, spared: processTableSpared());
    }))->toBe([]);
})->with([1, 0, -1]);

it('stops the lowest id that names one process', function (): void {
    expect(processTableStopped([''], static function (Closure $run, Listed $listed): void {
        ProcessTable::stop(2, $run, $listed, spared: processTableSpared());
    }))->toBe(['kill -STOP 2', 'listing', 'kill -KILL 2']);
});

it('leaves every id that names no one process out of what it signals', function (): void {
    expect(ProcessTable::killing(processTableSpared(), 1, 101, 0, 2, -1))->toBe(['kill', '-KILL', '101', '2']);
});

it('kills, after the tree of a process that leads its session, the whole session at once', function (): void {
    expect(processTableStopped(["100 1\n101 100\n"], static function (Closure $run, Listed $listed): void {
        ProcessTable::stop(100, $run, $listed, leads: true, spared: processTableSpared());
    }))->toBe([
        'kill -STOP 100',
        'listing',
        'kill -STOP 101',
        'listing',
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
    expect(processTableStopped([''], static function (Closure $run, Listed $listed): void {
        ProcessTable::stop(100, $run, $listed);
    }))->toBe(['listing', 'kill -STOP 100', 'listing', 'kill -KILL 100']);
});

it('never stops the caller, a process above it, nor one of them found under the process it stops', function (int $pid): void {
    expect(processTableStopped(["100 1\n10 100\n101 100\n"], static function (Closure $run, Listed $listed) use ($pid): void {
        ProcessTable::stop($pid, $run, $listed, spared: processTableSpared());
    }))->toBe($pid === 100 ? ['kill -STOP 100', 'listing', 'kill -STOP 101', 'listing', 'kill -KILL 101 100'] : []);
})->with([10, 20, 100]);

it('kills every process a cgroup holds, the process last, and then its session, without a listing', function (bool $leads): void {
    $ran = processTableStopped([''], static function (Closure $run) use ($leads): void {
        ProcessTable::stopHeld(100, [100, 300, 101, 10], $run, leads: $leads, spared: processTableSpared());
    });

    expect($ran)->toBe($leads ? ['kill -KILL 300 101 100', 'kill -KILL -- -100'] : ['kill -KILL 300 101 100']);
})->with(['a session leader' => [true], 'a process' => [false]]);

it('kills the process a cgroup was made for where the cgroup holds nothing', function (): void {
    expect(processTableStopped([''], static function (Closure $run): void {
        ProcessTable::stopHeld(100, [], $run, leads: false, spared: processTableSpared());
    }))->toBe(['kill -KILL 100']);
});

it('stops nothing a cgroup holds where the process it was made for is one the caller spares', function (int $pid): void {
    expect(processTableStopped([''], static function (Closure $run) use ($pid): void {
        ProcessTable::stopHeld($pid, [300], $run, leads: true, spared: processTableSpared());
    }))->toBe([]);
})->with([10, 20, 1]);

it('never kills the group or the session of the caller, nor the group a process above it leads', function (int $leader): void {
    $ran = [];

    ProcessTable::end($leader, static function (array $command) use (&$ran): string {
        $ran[] = implode(' ', $command);

        return '';
    }, processTableSpared());

    expect($ran)->toBe($leader === 30 ? ['kill -KILL -- -30'] : [])
        ->and(ProcessTable::ending(processTableSpared(), $leader))->toBe($leader === 30 ? ['kill', '-KILL', '--', '-30'] : ['kill', '-KILL']);
})->with([10, 20, 1, 0, -1, 30]);
