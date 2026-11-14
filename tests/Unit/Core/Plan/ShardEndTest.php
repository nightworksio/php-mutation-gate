<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\JobEnding;
use NightWorksIO\MutationGate\Core\Plan\ShardEnd;
use NightWorksIO\MutationGate\Core\Plan\ShardEndRecord;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

it('says what ended a shard\'s job, each way the CI names', function (JobEnding $how, string $why): void {
    expect(ShardEnd::of($how, NotGiven::value(), NotGiven::value())->why())->toBe($why);
})->with([
    'shutdown' => [JobEnding::Shutdown, 'its runner received a shutdown signal, which stopped the job'],
    'lost' => [JobEnding::Lost, 'its runner lost contact with the CI, as a runner starved of memory or CPU does'],
    'cancelled' => [JobEnding::Cancelled, 'the CI cancelled it'],
    'timed out' => [JobEnding::TimedOut, 'its job ran past its time limit'],
    'failed' => [JobEnding::Failed, 'its job failed before the shard wrote a result'],
]);

it('names the shard whose failure a cancel came after, and where the CI shows the job', function (): void {
    expect(ShardEnd::of(JobEnding::Cancelled, 'https://ci.example/job/7', ShardId::of(10))->why())
        ->toBe('the CI cancelled it once shard 10 failed, as fail-fast cancels the other shards: https://ci.example/job/7');
});

it('reads how a job ended, its address and the shard a cancel came after, as the file holds them', function (): void {
    $said = '{"how": "cancelled", "job": "https://ci.example/job/7", "after": 10}';

    expect(ShardEndRecord::decode($said))
        ->toEqual(ShardEnd::of(JobEnding::Cancelled, 'https://ci.example/job/7', ShardId::of(10)))
        ->and(ShardEndRecord::decode('{"how": "timed-out"}'))
        ->toEqual(ShardEnd::of(JobEnding::TimedOut, NotGiven::value(), NotGiven::value()));
});

it('reads no shard before the first as the one a cancel came after', function (): void {
    expect(ShardEndRecord::decode('{"how": "cancelled", "after": 0}'))
        ->toEqual(ShardEnd::of(JobEnding::Cancelled, NotGiven::value(), NotGiven::value()))
        ->and(ShardEndRecord::decode('{"how": "cancelled", "after": 1}'))
        ->toEqual(ShardEnd::of(JobEnding::Cancelled, NotGiven::value(), ShardId::of(1)));
});

it('reads a file it cannot read as saying nothing', function (string $said): void {
    expect(ShardEndRecord::decode($said))->toEqual(NotGiven::value());
})->with([
    'not JSON' => ['not JSON'],
    'an ending it does not know' => ['{"how": "evicted"}'],
    'no ending' => ['{"after": 3}'],
    'an address that is no text' => ['{"how": "lost", "job": 7}'],
    'a shard that is no number' => ['{"how": "cancelled", "after": "3"}'],
]);

it('says how a job ended by the name the file gives it', function (): void {
    expect(ShardEnd::of(JobEnding::Lost, NotGiven::value(), NotGiven::value())->how())->toBe(JobEnding::Lost);
});
