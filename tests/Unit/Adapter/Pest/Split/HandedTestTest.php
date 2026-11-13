<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\HandedTest;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Core\NotGiven;
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

    expect($ended->passed)->toBeTrue()
        ->and($ended->failed)->toBe([['m1', 'MoneyTest::subtracts'], ['m3', 'MoneyTest::subtracts']]);
});

it('reads no end where the test\'s process never ended the test, or wrote nothing', function (): void {
    $results = sprintf('%s/results-1-0.jsonl', Scratch::directory());
    file_put_contents($results, StreamLine::child('m1', 'MoneyTest::adds', failed: true, seconds: 0.1));

    expect(HandedTest::read($results, 0, 'MoneyTest::adds')->passed)->toBeInstanceOf(NotGiven::class)
        ->and(HandedTest::read(sprintf('%s/missing.jsonl', Scratch::directory()), 0, 'MoneyTest::adds')->failed)->toBe([]);
});
