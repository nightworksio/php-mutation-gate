<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\SplitStream;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'on' => [static fn(): Setting => SplitStream::on(), 'splitStream.enabled', true, 'on'],
    'off' => [static fn(): Setting => SplitStream::off(), 'splitStream.enabled', false, 'off'],
    'children' => [static fn(): Setting => SplitStream::children(4), 'splitStream.children', 4, 'children'],
    'checkingChanges' => [static fn(): Setting => SplitStream::checkingChanges(), 'splitStream.changeCheck', true, 'checkingChanges'],
    'forkingUnchecked' => [static fn(): Setting => SplitStream::forkingUnchecked(), 'splitStream.changeCheck', false, 'forkingUnchecked'],
    'killersFirst' => [static fn(): Setting => SplitStream::killersFirst(), 'splitStream.killersFirst', true, 'killersFirst'],
    'everyTestAlike' => [static fn(): Setting => SplitStream::everyTestAlike(), 'splitStream.killersFirst', false, 'everyTestAlike'],
    'killerStreams' => [static fn(): Setting => SplitStream::killerStreams(), 'splitStream.killerStreams', true, 'killerStreams'],
    'wholeFirstPass' => [static fn(): Setting => SplitStream::wholeFirstPass(), 'splitStream.killerStreams', false, 'wholeFirstPass'],
    'bootOnce' => [static fn(): Setting => SplitStream::bootOnce(), 'splitStream.bootOnce', true, 'bootOnce'],
    'bootPerTest' => [static fn(): Setting => SplitStream::bootPerTest(), 'splitStream.bootOnce', false, 'bootPerTest'],
    'reconnect' => [static fn(): Setting => SplitStream::reconnect(), 'splitStream.reconnect', true, 'reconnect'],
    'connectionsFallBack' => [static fn(): Setting => SplitStream::connectionsFallBack(), 'splitStream.reconnect', false, 'connectionsFallBack'],
    'shardingByTests' => [static fn(): Setting => SplitStream::shardingByTests(), 'splitStream.sharding', 'tests', 'shardingByTests'],
    'shardingBySource' => [static fn(): Setting => SplitStream::shardingBySource(), 'splitStream.sharding', 'source', 'shardingBySource'],
    'earlyWhereNothingMatches' => [static fn(): Setting => SplitStream::earlyWhereNothingMatches(), 'splitStream.early', 'no-match', 'earlyWhereNothingMatches'],
    'alwaysEarly' => [static fn(): Setting => SplitStream::alwaysEarly(), 'splitStream.early', 'always', 'alwaysEarly'],
    'earlyUnprovenOnly' => [static fn(): Setting => SplitStream::earlyUnprovenOnly(), 'splitStream.early', 'unproven-only', 'earlyUnprovenOnly'],
    'neverEarly' => [static fn(): Setting => SplitStream::neverEarly(), 'splitStream.early', 'never', 'neverEarly'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|int|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('SplitStream::%s(', $method));
})->with($calls);
