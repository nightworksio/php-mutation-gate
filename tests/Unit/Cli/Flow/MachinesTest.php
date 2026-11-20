<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Machines;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('names the processor a cpuinfo file names, and none where it names none or is not there', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'cpuinfo', "processor\t: 0\nvendor_id\t: AuthenticAMD\nmodel name\t: AMD EPYC 7763 64-Core Processor \nflags\t\t: fpu\n");
    Scratch::write($project, 'bare', "processor\t: 0\n");

    expect(Machines::cpuIn(sprintf('%s/cpuinfo', $project)))->toBe('AMD EPYC 7763 64-Core Processor')
        ->and(Machines::cpuIn(sprintf('%s/bare', $project)))->toEqual(NotGiven::value())
        ->and(Machines::cpuIn(sprintf('%s/none', $project)))->toEqual(NotGiven::value());
});

it('times each round from its start to its end and nothing else, leaves the first out and takes the median', function (): void {
    $events = [];
    $now = 0;
    $lengths = [50, 3, 1, 9, 2, 4];
    $clock = function () use (&$events, &$now): int {
        $events[] = 'clock';

        return $now;
    };
    $round = function () use (&$events, &$now, &$lengths): bool {
        $events[] = 'round';
        $now += (array_shift($lengths) ?? 0) * Seconds::NANOSECONDS;

        return true;
    };

    $median = Machines::timedOn($clock, $round);

    expect($events)->toBe(array_merge(...array_fill(0, Machines::ROUNDS + 1, ['clock', 'round', 'clock'])))
        ->and($median)->toEqual(Seconds::of(3.0));
});

it('gives no figure where a round fails, whether the first or a later one', function (): void {
    $rounds = 0;
    $failsThird = function () use (&$rounds): bool {
        $rounds++;

        return $rounds !== 3;
    };

    expect(Machines::timedOn(fn(): int => 0, fn(): bool => false))->toEqual(NotGiven::value())
        ->and(Machines::timedOn(fn(): int => 0, $failsThird))->toEqual(NotGiven::value())
        ->and($rounds)->toBe(3);
});

it('times a round of work on the system clock', function (): void {
    $seconds = Machines::timed(fn(): bool => hash('sha256', str_repeat('a', 100_000)) !== hash('sha256', 'b'));

    expect($seconds instanceof Seconds ? $seconds->seconds() : 0.0)->toBeGreaterThan(0.0);
});
