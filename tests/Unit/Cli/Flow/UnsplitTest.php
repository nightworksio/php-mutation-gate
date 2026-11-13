<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Unsplit;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('warns that a shard ran each mutant on its own where split streams are on and this PHP cannot fork', function (): void {
    $split = Configs::settings(['runner' => 'pest', 'pest' => ['patch' => true], 'splitStream' => ['enabled' => true]]);

    expect(Unsplit::warnings($split, ShardId::of(2), forks: false))
        ->toEqual(Warnings::of(Warning::that('Shard 2 ran each mutant on its own: splitStream.enabled needs pcntl and posix, which this PHP lacks.')))
        ->and(Unsplit::warnings(Configs::settings(['runner' => 'pest']), ShardId::of(2), forks: false))->toEqual(Warnings::none());
});
