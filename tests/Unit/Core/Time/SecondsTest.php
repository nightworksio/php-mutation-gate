<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('holds a duration in seconds', function (): void {
    expect(Seconds::of(12.4)->seconds())->toBe(12.4);
});

it('reads a duration written in days, hours, minutes and seconds', function (string $written, float $seconds): void {
    expect(Seconds::parse($written))->toEqual(Seconds::of($seconds));
})->with([
    'seconds' => ['90s', 90.0],
    'minutes' => ['15m', 900.0],
    'hours' => ['2h', 7200.0],
    'hours and minutes' => ['1h30m', 5400.0],
    'all three' => ['2h3m4s', 7384.0],
    'days' => ['7d', 604_800.0],
    'all four' => ['1d2h3m4s', 93_784.0],
    'nothing at all' => ['0s', 0.0],
]);

it('refuses what is not a duration, and says how to write one', function (string $written): void {
    expect(Seconds::parse($written))->toEqual(CannotJudge::because(sprintf('"%s" is not a duration. Write it as 90s, 15m, 1h30m or 7d.', $written)));
})->with(['', '90', '1m30h', 'x90s', "90s\n", '1.5h', '1h2d']);

it('says a duration to the nearest unit it shows', function (float $seconds, string $text): void {
    expect(Seconds::of($seconds)->text())->toBe($text);
})->with([
    'nothing' => [0.0, '0s'],
    'under a second' => [0.4, '0s'],
    'seconds' => [45.0, '45s'],
    'just under a minute' => [59.4, '59s'],
    'a minute, rounded up' => [59.6, '1m'],
    'minutes' => [370.0, '6m'],
    'an hour, rounded up' => [3599.0, '1h'],
    'hours and minutes' => [6060.0, '1h 41m'],
    'whole hours' => [7200.0, '2h'],
]);

it('says a test\'s duration to the hundredth of a second under a minute', function (float $seconds, string $text): void {
    expect(Seconds::of($seconds)->preciseText())->toBe($text);
})->with([
    [0.254, '0.25s'],
    [12.0, '12.00s'],
    [59.6, '1m'],
    [6060.0, '1h 41m'],
]);

it('says its length in whole nanoseconds, rounded, and in minutes', function (): void {
    expect(Seconds::of(40.25)->nanoseconds())->toBe(40_250_000_000)
        ->and(Seconds::of(0.0000000015)->nanoseconds())->toBe(2)
        ->and(Seconds::of(840.0)->inMinutes())->toBe(14.0)
        ->and(Seconds::of(90.0)->inMinutes())->toBe(1.5);
});

it('says its length in whole microseconds, rounded', function (): void {
    expect(Seconds::of(1.5)->microseconds())->toBe(1_500_000)
        ->and(Seconds::of(0.0000015)->microseconds())->toBe(2);
});

it('measures the time from one moment to a later one, to the microsecond', function (): void {
    expect(Seconds::between(new DateTimeImmutable('2026-01-01T00:00:00.250000Z'), new DateTimeImmutable('2026-01-01T00:00:02.500000Z')))
        ->toEqual(Seconds::of(2.25));
});

it('writes a duration back in the largest units it holds, days among them', function (float $seconds, string $written): void {
    expect(Seconds::of($seconds)->written())->toBe($written)
        ->and(Seconds::parse($written))->toEqual(Seconds::of($seconds));
})->with([
    'nothing' => [0.0, '0s'],
    'seconds' => [90.0, '1m30s'],
    'a week' => [604_800.0, '7d'],
    'a day and more' => [93_784.0, '1d2h3m4s'],
]);
