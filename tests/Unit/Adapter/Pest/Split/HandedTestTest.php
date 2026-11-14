<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
use NightWorksIO\MutationGate\Adapter\Pest\Split\HandedEnd;
use NightWorksIO\MutationGate\Adapter\Pest\Split\HandedTest;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Kills;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads how a handed test ended after the offset: passed, and each mutant a child failed or ended in', function (): void {
    $results = sprintf('%s/results-1-0.jsonl', Scratch::directory());
    $before = sprintf(
        '%s%s',
        StreamLine::parent('MoneyTest::adds', passed: false, seconds: 0.1),
        StreamLine::child('m0', 'MoneyTest::subtracts', failed: true, seconds: 0.1),
    );
    file_put_contents($results, implode('', [
        $before,
        StreamLine::child('m1', 'MoneyTest::subtracts', failed: true, seconds: 0.1),
        StreamLine::child('m2', 'MoneyTest::subtracts', failed: false, seconds: 0.1),
        StreamLine::endedChild('m3', 'MoneyTest::subtracts', 255),
        StreamLine::child('m4', 'MoneyTest::other', failed: true, seconds: 0.1),
        StreamLine::parent('MoneyTest::subtracts', passed: true, seconds: 0.3),
    ]));
    $ended = HandedTest::read($results, strlen($before), 'MoneyTest::subtracts');

    expect($ended->end)->toBe(HandedEnd::Passed)
        ->and($ended->failed)->toBe([['m1', 'MoneyTest::subtracts'], ['m3', 'MoneyTest::subtracts']]);
});

it('reads no end where the test\'s process never ended the test, or wrote nothing', function (): void {
    $results = sprintf('%s/results-1-0.jsonl', Scratch::directory());
    file_put_contents($results, StreamLine::child('m1', 'MoneyTest::adds', failed: true, seconds: 0.1));

    expect(HandedTest::read($results, 0, 'MoneyTest::adds')->end)->toBe(HandedEnd::Unended)
        ->and(HandedTest::read(sprintf('%s/missing.jsonl', Scratch::directory()), 0, 'MoneyTest::adds')->failed)->toBe([]);
});

it('reads a passed test\'s failed mutants into the kills as killed, and writes a fallback for a test its process never ended', function (): void {
    $directory = Scratch::directory();
    $results = sprintf('%s/results-1-0.jsonl', $directory);
    file_put_contents($results, implode('', [
        StreamLine::child('m1', 'MoneyTest::adds', failed: true, seconds: 0.1),
        StreamLine::parent('MoneyTest::adds', passed: true, seconds: 0.3),
        StreamLine::child('m2', 'MoneyTest::subtracts', failed: true, seconds: 0.1),
    ]));
    $plan = new StreamPlan(sprintf('%s/stream.jsonl', $directory), [], [], [], 1, new ChildLimit(0.0, 0.0, 0.0));
    $kills = new Kills();

    HandedTest::read($results, 0, 'MoneyTest::adds')->into($kills, $plan);
    HandedTest::read($results, 0, 'MoneyTest::subtracts')->into($kills, $plan);

    expect([$kills->has('m1'), $kills->has('m2')])->toBe([true, false])
        ->and(file_get_contents($plan->results))->toBe(StreamLine::fallback('MoneyTest::subtracts'));
});
