<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\EarlyStreams;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\SplitStream;
use NightWorksIO\MutationGate\Config\StreamShards;
use NightWorksIO\MutationGate\Config\StreamWork;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'on' => [static fn(): Setting => SplitStream::on(), 'splitStream.enabled', true, 'SplitStream::on'],
    'off' => [static fn(): Setting => SplitStream::off(), 'splitStream.enabled', false, 'SplitStream::off'],
    'children' => [static fn(): Setting => SplitStream::children(4), 'splitStream.children', 4, 'SplitStream::children'],
    'childrenByCores' => [static fn(): Setting => SplitStream::childrenByCores(), 'splitStream.children', 'cores', 'SplitStream::childrenByCores'],
    'checkingChanges' => [static fn(): Setting => SplitStream::checkingChanges(), 'splitStream.changeCheck', true, 'SplitStream::checkingChanges'],
    'forkingUnchecked' => [static fn(): Setting => SplitStream::forkingUnchecked(), 'splitStream.changeCheck', false, 'SplitStream::forkingUnchecked'],
    'killersFirst' => [static fn(): Setting => SplitStream::killersFirst(), 'splitStream.killersFirst', true, 'SplitStream::killersFirst'],
    'everyTestAlike' => [static fn(): Setting => SplitStream::everyTestAlike(), 'splitStream.killersFirst', false, 'SplitStream::everyTestAlike'],
    'fallbackOwnRun' => [static fn(): Setting => SplitStream::fallbackOwnRun(), 'splitStream.fallbackOwnRun', true, 'SplitStream::fallbackOwnRun'],
    'fallbackSecondPass' => [static fn(): Setting => SplitStream::fallbackSecondPass(), 'splitStream.fallbackOwnRun', false, 'SplitStream::fallbackSecondPass'],
    'coveringTestsOnly' => [static fn(): Setting => SplitStream::coveringTestsOnly(), 'splitStream.coveringTestsOnly', true, 'SplitStream::coveringTestsOnly'],
    'wholeCoveringFiles' => [static fn(): Setting => SplitStream::wholeCoveringFiles(), 'splitStream.coveringTestsOnly', false, 'SplitStream::wholeCoveringFiles'],
    'killerStreams' => [static fn(): Setting => SplitStream::killerStreams(), 'splitStream.killerStreams', true, 'SplitStream::killerStreams'],
    'wholeFirstPass' => [static fn(): Setting => SplitStream::wholeFirstPass(), 'splitStream.killerStreams', false, 'SplitStream::wholeFirstPass'],
    'bootOnce' => [static fn(): Setting => SplitStream::bootOnce(), 'splitStream.bootOnce', true, 'SplitStream::bootOnce'],
    'bootPerTest' => [static fn(): Setting => SplitStream::bootPerTest(), 'splitStream.bootOnce', false, 'SplitStream::bootPerTest'],
    'reconnect' => [static fn(): Setting => SplitStream::reconnect(), 'splitStream.reconnect', true, 'SplitStream::reconnect'],
    'connectionsFallBack' => [static fn(): Setting => SplitStream::connectionsFallBack(), 'splitStream.reconnect', false, 'SplitStream::connectionsFallBack'],
    'parentDeadline' => [static fn(): Setting => StreamWork::parentDeadline(), 'splitStream.parentDeadline', true, 'StreamWork::parentDeadline'],
    'noParentDeadline' => [static fn(): Setting => StreamWork::noParentDeadline(), 'splitStream.parentDeadline', false, 'StreamWork::noParentDeadline'],
    'slowdownThroughTheShard' => [static fn(): Setting => StreamWork::slowdownThroughTheShard(), 'splitStream.slowdownCarry', 'shard', 'StreamWork::slowdownThroughTheShard'],
    'slowdownThroughTheRun' => [static fn(): Setting => StreamWork::slowdownThroughTheRun(), 'splitStream.slowdownCarry', 'run', 'StreamWork::slowdownThroughTheRun'],
    'slowdownPerPass' => [static fn(): Setting => StreamWork::slowdownPerPass(), 'splitStream.slowdownCarry', 'none', 'StreamWork::slowdownPerPass'],
    'firstForkAtTheCheapest' => [static fn(): Setting => StreamWork::firstForkAtTheCheapest(), 'splitStream.firstFork', 'cheapest', 'StreamWork::firstForkAtTheCheapest'],
    'firstForkAtEvery' => [static fn(): Setting => StreamWork::firstForkAtEvery(), 'splitStream.firstFork', 'every', 'StreamWork::firstForkAtEvery'],
    'reachableOnly' => [static fn(): Setting => StreamWork::reachableOnly(), 'splitStream.reachableOnly', true, 'StreamWork::reachableOnly'],
    'everyMutant' => [static fn(): Setting => StreamWork::everyMutant(), 'splitStream.reachableOnly', false, 'StreamWork::everyMutant'],
    'earlyVerdicts' => [static fn(): Setting => StreamWork::earlyVerdicts(), 'splitStream.earlyVerdicts', true, 'StreamWork::earlyVerdicts'],
    'verdictsAtTheEnd' => [static fn(): Setting => StreamWork::verdictsAtTheEnd(), 'splitStream.earlyVerdicts', false, 'StreamWork::verdictsAtTheEnd'],
    'partsPerProcess' => [static fn(): Setting => StreamWork::partsPerProcess(2), 'splitStream.partsPerProcess', 2, 'StreamWork::partsPerProcess'],
    'deadlineBackoff' => [static fn(): Setting => StreamWork::deadlineBackoff(3), 'splitStream.deadlineBackoff', 3, 'StreamWork::deadlineBackoff'],
    'byTests' => [static fn(): Setting => StreamShards::byTests(), 'splitStream.sharding', 'tests', 'StreamShards::byTests'],
    'byTestsInEveryPart' => [static fn(): Setting => StreamShards::byTestsInEveryPart(), 'splitStream.sharding', 'tests-every-part', 'StreamShards::byTestsInEveryPart'],
    'bySource' => [static fn(): Setting => StreamShards::bySource(), 'splitStream.sharding', 'source', 'StreamShards::bySource'],
    'judgedWhole' => [static fn(): Setting => StreamShards::judgedWhole(), 'splitStream.judging', 'whole', 'StreamShards::judgedWhole'],
    'judgedParted' => [static fn(): Setting => StreamShards::judgedParted(), 'splitStream.judging', 'parted', 'StreamShards::judgedParted'],
    'earlyWhereNothingMatches' => [static fn(): Setting => EarlyStreams::whereNothingMatches(), 'splitStream.early', 'no-match', 'EarlyStreams::whereNothingMatches'],
    'alwaysEarly' => [static fn(): Setting => EarlyStreams::always(), 'splitStream.early', 'always', 'EarlyStreams::always'],
    'earlyUnprovenOnly' => [static fn(): Setting => EarlyStreams::unprovenOnly(), 'splitStream.early', 'unproven-only', 'EarlyStreams::unprovenOnly'],
    'neverEarly' => [static fn(): Setting => EarlyStreams::never(), 'splitStream.early', 'never', 'EarlyStreams::never'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|int|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);
