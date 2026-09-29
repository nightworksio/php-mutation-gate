<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('holds a duration in seconds', function (): void {
    expect(Seconds::of(12.4)->seconds())->toBe(12.4);
});

it('reads a duration written in hours, minutes and seconds', function (string $written, float $seconds): void {
    expect(Seconds::parse($written))->toEqual(Seconds::of($seconds));
})->with([
    'seconds' => ['90s', 90.0],
    'minutes' => ['15m', 900.0],
    'hours' => ['2h', 7200.0],
    'hours and minutes' => ['1h30m', 5400.0],
    'all three' => ['2h3m4s', 7384.0],
    'nothing at all' => ['0s', 0.0],
]);

it('refuses what is not a duration, and says how to write one', function (string $written): void {
    expect(Seconds::parse($written))->toEqual(CannotJudge::because(sprintf('"%s" is not a duration. Write it as 90s, 15m or 1h30m.', $written)));
})->with(['', '90', '1m30h', 'x90s', "90s\n", '1.5h']);
