<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\CostMode;
use NightWorksIO\MutationGate\Core\Config\ShardTail;

it('runs a wave\'s tail as shards.tail says, auto where it comes to more than the pool or the minutes are billed', function (ShardTail $tail, int $jobs, CostMode $mode, bool $runs): void {
    expect($tail->runs($jobs > 19, $mode))->toBe($runs);
})->with([
    'auto within the pool' => [ShardTail::Auto, 19, CostMode::Wall, false],
    'auto past the pool' => [ShardTail::Auto, 20, CostMode::Wall, true],
    'auto, minutes, within the pool' => [ShardTail::Auto, 3, CostMode::Minutes, true],
    'stop past the pool' => [ShardTail::Stop, 75, CostMode::Minutes, false],
    'run within the pool' => [ShardTail::Run, 1, CostMode::Wall, true],
]);
