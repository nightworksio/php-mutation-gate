<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Plan\JobTailRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('writes and reads back how a plan\'s jobs stop, and reads a file that names no limit or pool as jobs that stop at their target', function (JobTail $tail, string $json): void {
    expect(JobTailRecord::of($tail))->toBe(json_decode($json, associative: true))
        ->and(JobTailRecord::read(Node::decode($json)))->toEqual($tail);
})->with([
    'unknown' => [fn(): JobTail => JobTail::unknown(), '[]'],
    'stopping' => [fn(): JobTail => JobTail::of(Seconds::of(21600.0), 19), '{"tailLimit": 21600.0, "tailPool": 19}'],
    'running' => [fn(): JobTail => JobTail::of(Seconds::of(3600.0), 4)->running(), '{"tailLimit": 3600.0, "tailPool": 4, "ownTail": true}'],
]);

it('reads ownTail without a limit and a pool as jobs that stop at their target', function (): void {
    expect(JobTailRecord::read(Node::decode('{"ownTail": true, "tailPool": 3}')))->toEqual(JobTail::unknown());
});
