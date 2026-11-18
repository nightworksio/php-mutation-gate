<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\FirstForks;
use NightWorksIO\MutationGate\Core\Config\FirstFork;

it('forks a mutant first at the ledger\'s likely killer, and one it names none of at the part\'s covering test the map timed fastest', function (): void {
    $first = FirstForks::of(FirstFork::Cheapest, ['slow' => 2.0, 'fast' => 0.25, 'middle' => 0.5]);

    expect([
        $first->at('slow', ['slow', 'fast']),
        $first->at('', ['slow', 'middle', 'fast']),
        $first->at('', ['middle', 'slow']),
        $first->at('', []),
    ])->toBe(['slow', 'fast', 'middle', '']);
});

it('takes the first of the covering tests timed alike, and a test the map did not time after every one it did', function (): void {
    $first = FirstForks::of(FirstFork::Cheapest, ['one' => 0.5, 'two' => 0.5, 'timed' => 9.0]);

    expect([
        $first->at('', ['two', 'one']),
        $first->at('', ['untimed', 'timed']),
        $first->at('', ['untimed', 'unknown']),
    ])->toBe(['two', 'timed', 'untimed']);
});

it('forks a mutant the ledger names no killer of at every covering test under splitStream.firstFork every, or where it forks at killers alone', function (): void {
    $times = ['slow' => 2.0, 'fast' => 0.25];

    expect([
        FirstForks::of(FirstFork::Every, $times)->at('', ['slow', 'fast']),
        FirstForks::of(FirstFork::Every, $times)->at('slow', ['slow', 'fast']),
        FirstForks::atKillers()->at('', ['slow', 'fast']),
    ])->toBe(['', 'slow', '']);
});
