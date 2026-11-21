<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\ShardDeadline;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\JobStop;
use NightWorksIO\MutationGate\Core\Cost\JobsLearned;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Plan\PlanLearned;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Flows;

it('ends a shard at its target less its setup, its wind-down and shards.margin, or sooner at a budget or shards.jobLimit, the stop where the case gives none', function (Budget|Shards $config, float $left): void {
    $now = new DateTimeImmutable('2026-11-15T12:00:00Z');
    $settings = Flows::settings(Shards::target('10m'), Shards::margin('45s'), Shards::setup('1m'), $config);
    $seconds = $left < 0.0 ? 600.0 - 60.0 - JobStop::WIND_DOWN_SECONDS - 45.0 : $left;

    expect(ShardDeadline::of($settings, PlanLearned::none(), Variables::of([]), $now)->deadline())
        ->toEqual(Deadline::after($now, Seconds::of($seconds)));
})->with([
    'the target alone' => [fn(): Shards => Shards::progress('3m'), -1.0],
    'a shorter budget' => [fn(): Budget => Budget::of('5m'), 300.0],
    'a longer budget' => [fn(): Budget => Budget::of('2h'), -1.0],
    'a shorter job limit' => [fn(): Shards => Shards::jobLimit('4m'), 240.0],
    'a longer job limit' => [fn(): Shards => Shards::jobLimit('1h'), -1.0],
    'a target nothing is left of' => [fn(): Shards => Shards::target('1m'), 0.0],
]);

it('measures the setup from when the job says it began, and takes what the plan learned where it says nothing', function (): void {
    $now = new DateTimeImmutable('2026-11-15T12:00:00Z');
    $settings = Flows::settings(Shards::target('10m'), Shards::margin('45s'), Shards::setup('1m'));
    $learned = PlanLearned::none()->timing(JobsLearned::learned(Seconds::of(90.0), Seconds::of(12.0)));
    $began = Variables::of([ShardDeadline::JOB_STARTED => (string) ($now->getTimestamp() - 50)]);

    $measured = ShardDeadline::of($settings, $learned, $began, $now);
    $unsaid = ShardDeadline::of($settings, $learned, Variables::of([]), $now);
    $garbled = ShardDeadline::of($settings, PlanLearned::none(), Variables::of([ShardDeadline::JOB_STARTED => 'soon']), $now);
    $future = ShardDeadline::of($settings, $learned, Variables::of([ShardDeadline::JOB_STARTED => (string) ($now->getTimestamp() + 99)]), $now);

    expect($measured->deadline())->toEqual(Deadline::after($now, Seconds::of(600.0 - 50.0 - 12.0 - 45.0)))
        ->and($measured->setup())->toEqual(Seconds::of(50.0))
        ->and($unsaid->deadline())->toEqual(Deadline::after($now, Seconds::of(600.0 - 90.0 - 12.0 - 45.0)))
        ->and($unsaid->setup())->toBeInstanceOf(NotGiven::class)
        ->and($garbled->deadline())->toEqual(Deadline::after($now, Seconds::of(600.0 - 60.0 - JobStop::WIND_DOWN_SECONDS - 45.0)))
        ->and($future->setup())->toEqual(Seconds::of(0.0));
});

it('fits estimates to the deadline under a budget, and to nothing under the stop alone', function (): void {
    $deadline = Deadline::after(new DateTimeImmutable('2026-11-15T12:00:00Z'), Seconds::of(60.0));

    expect(ShardDeadline::fittedTo(Flows::settings(Budget::of('1m')), $deadline))->toBe($deadline)
        ->and(ShardDeadline::fittedTo(Flows::settings(), $deadline))->toEqual(Unlimited::time());
});

it('runs a job that runs its own tail to its limit less its setup, its wind-down and shards.margin, or to shards.jobLimit', function (): void {
    $now = new DateTimeImmutable('2026-11-15T12:00:00Z');
    $began = Variables::of([ShardDeadline::JOB_STARTED => (string) ($now->getTimestamp() - 50)]);
    $tail = PlanLearned::none()
        ->timing(JobsLearned::learned(Seconds::of(90.0), Seconds::of(12.0)))
        ->tailing(JobTail::of(Seconds::minutes(60), 19)->running());
    $stopping = PlanLearned::none()->tailing(JobTail::of(Seconds::minutes(60), 19));

    expect(ShardDeadline::of(Flows::settings(Shards::margin('45s')), $tail, $began, $now)->deadline())
        ->toEqual(Deadline::after($now, Seconds::of(3600.0 - 50.0 - 12.0 - 45.0)))
        ->and(ShardDeadline::of(Flows::settings(Shards::jobLimit('20m')), $tail, $began, $now)->deadline())
        ->toEqual(Deadline::after($now, Seconds::of(1200.0)))
        ->and(ShardDeadline::of(Flows::settings(Shards::margin('45s')), $stopping, $began, $now)->deadline())
        ->toEqual(Deadline::after($now, Seconds::of(600.0 - 50.0 - JobStop::WIND_DOWN_SECONDS - 45.0)));
});

it('reads a job\'s limit in whole minutes from the CI, and six hours where it hands none or no number', function (string $handed, float $seconds): void {
    $environment = Variables::of($handed === '' ? [] : [ShardDeadline::JOB_LIMIT => $handed]);

    expect(ShardDeadline::limitOf($environment))->toEqual(Seconds::of($seconds));
})->with([
    'ninety minutes' => ['90', 5400.0],
    'none' => ['', 21600.0],
    'no number' => ['soon', 21600.0],
]);
