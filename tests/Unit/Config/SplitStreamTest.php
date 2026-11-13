<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\SplitStream;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = static fn(): array => [
    'on' => [SplitStream::on(), 'splitStream.enabled', true, 'on'],
    'off' => [SplitStream::off(), 'splitStream.enabled', false, 'off'],
    'children' => [SplitStream::children(4), 'splitStream.children', 4, 'children'],
    'checkingChanges' => [SplitStream::checkingChanges(), 'splitStream.changeCheck', true, 'checkingChanges'],
    'forkingUnchecked' => [SplitStream::forkingUnchecked(), 'splitStream.changeCheck', false, 'forkingUnchecked'],
    'killersFirst' => [SplitStream::killersFirst(), 'splitStream.killersFirst', true, 'killersFirst'],
    'everyTestAlike' => [SplitStream::everyTestAlike(), 'splitStream.killersFirst', false, 'everyTestAlike'],
    'killerStreams' => [SplitStream::killerStreams(), 'splitStream.killerStreams', true, 'killerStreams'],
    'wholeFirstPass' => [SplitStream::wholeFirstPass(), 'splitStream.killerStreams', false, 'wholeFirstPass'],
    'bootOnce' => [SplitStream::bootOnce(), 'splitStream.bootOnce', true, 'bootOnce'],
    'bootPerTest' => [SplitStream::bootPerTest(), 'splitStream.bootOnce', false, 'bootPerTest'],
    'reconnect' => [SplitStream::reconnect(), 'splitStream.reconnect', true, 'reconnect'],
    'connectionsFallBack' => [SplitStream::connectionsFallBack(), 'splitStream.reconnect', false, 'connectionsFallBack'],
    'shardingByTests' => [SplitStream::shardingByTests(), 'splitStream.sharding', 'tests', 'shardingByTests'],
    'shardingBySource' => [SplitStream::shardingBySource(), 'splitStream.sharding', 'source', 'shardingBySource'],
    'earlyWhereNothingMatches' => [SplitStream::earlyWhereNothingMatches(), 'splitStream.early', 'no-match', 'earlyWhereNothingMatches'],
    'alwaysEarly' => [SplitStream::alwaysEarly(), 'splitStream.early', 'always', 'alwaysEarly'],
    'earlyUnprovenOnly' => [SplitStream::earlyUnprovenOnly(), 'splitStream.early', 'unproven-only', 'earlyUnprovenOnly'],
    'neverEarly' => [SplitStream::neverEarly(), 'splitStream.early', 'never', 'neverEarly'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Setting $call, string $path, string|int|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('SplitStream::%s(', $method));
})->with($calls);
