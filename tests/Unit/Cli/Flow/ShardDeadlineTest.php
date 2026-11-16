<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\ShardDeadline;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Flows;

it('ends a shard at the sooner of its budget and shards.jobLimit, the limit where no budget is set', function (string $budget, float $seconds): void {
    $now = new DateTimeImmutable('2026-11-15T12:00:00Z');
    $settings = $budget === ''
        ? Flows::settings(Shards::jobLimit('1h'))
        : Flows::settings(Shards::jobLimit('1h'), Budget::of($budget));

    expect(ShardDeadline::of($settings, $now))->toEqual(Deadline::after($now, Seconds::of($seconds)));
})->with([
    'a shorter budget' => ['30m', 1800.0],
    'a budget the length of the limit' => ['1h', 3600.0],
    'a longer budget' => ['2h', 3600.0],
    'no budget' => ['', 3600.0],
]);

it('fits estimates to the deadline under a budget, and to nothing under the limit alone', function (): void {
    $deadline = Deadline::after(new DateTimeImmutable('2026-11-15T12:00:00Z'), Seconds::of(60.0));

    expect(ShardDeadline::fittedTo(Flows::settings(Budget::of('1m')), $deadline))->toBe($deadline)
        ->and(ShardDeadline::fittedTo(Flows::settings(), $deadline))->toEqual(Unlimited::time());
});
