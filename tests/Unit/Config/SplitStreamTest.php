<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\EarlyStreams;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\SplitStream;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'on' => [static fn(): Setting => SplitStream::on(), 'splitStream.enabled', true, 'SplitStream::on'],
    'off' => [static fn(): Setting => SplitStream::off(), 'splitStream.enabled', false, 'SplitStream::off'],
    'children' => [static fn(): Setting => SplitStream::children(4), 'splitStream.children', 4, 'SplitStream::children'],
    'checkingChanges' => [static fn(): Setting => SplitStream::checkingChanges(), 'splitStream.changeCheck', true, 'SplitStream::checkingChanges'],
    'forkingUnchecked' => [static fn(): Setting => SplitStream::forkingUnchecked(), 'splitStream.changeCheck', false, 'SplitStream::forkingUnchecked'],
    'killersFirst' => [static fn(): Setting => SplitStream::killersFirst(), 'splitStream.killersFirst', true, 'SplitStream::killersFirst'],
    'everyTestAlike' => [static fn(): Setting => SplitStream::everyTestAlike(), 'splitStream.killersFirst', false, 'SplitStream::everyTestAlike'],
    'killerStreams' => [static fn(): Setting => SplitStream::killerStreams(), 'splitStream.killerStreams', true, 'SplitStream::killerStreams'],
    'wholeFirstPass' => [static fn(): Setting => SplitStream::wholeFirstPass(), 'splitStream.killerStreams', false, 'SplitStream::wholeFirstPass'],
    'bootOnce' => [static fn(): Setting => SplitStream::bootOnce(), 'splitStream.bootOnce', true, 'SplitStream::bootOnce'],
    'bootPerTest' => [static fn(): Setting => SplitStream::bootPerTest(), 'splitStream.bootOnce', false, 'SplitStream::bootPerTest'],
    'reconnect' => [static fn(): Setting => SplitStream::reconnect(), 'splitStream.reconnect', true, 'SplitStream::reconnect'],
    'connectionsFallBack' => [static fn(): Setting => SplitStream::connectionsFallBack(), 'splitStream.reconnect', false, 'SplitStream::connectionsFallBack'],
    'shardingByTests' => [static fn(): Setting => SplitStream::shardingByTests(), 'splitStream.sharding', 'tests', 'SplitStream::shardingByTests'],
    'shardingBySource' => [static fn(): Setting => SplitStream::shardingBySource(), 'splitStream.sharding', 'source', 'SplitStream::shardingBySource'],
    'earlyWhereNothingMatches' => [static fn(): Setting => EarlyStreams::whereNothingMatches(), 'splitStream.early', 'no-match', 'EarlyStreams::whereNothingMatches'],
    'alwaysEarly' => [static fn(): Setting => EarlyStreams::always(), 'splitStream.early', 'always', 'EarlyStreams::always'],
    'earlyUnprovenOnly' => [static fn(): Setting => EarlyStreams::unprovenOnly(), 'splitStream.early', 'unproven-only', 'EarlyStreams::unprovenOnly'],
    'neverEarly' => [static fn(): Setting => EarlyStreams::never(), 'splitStream.early', 'never', 'EarlyStreams::never'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|int|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);
