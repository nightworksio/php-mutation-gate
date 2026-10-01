<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\RejectionRate;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Proof\AnalysersRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$histories = AnalyserHistories::none()
    ->with(AnalyserHistory::of('phpstan')
        ->withRate(RejectionRate::of('Plus', 60, 12))
        ->withRate(RejectionRate::of('Minus', 3, 0))
        ->withTime(CheckTime::of(63, Seconds::of(31.5))))
    ->with(AnalyserHistory::of('mago'));
$read = static fn(array $section): AnalyserHistories => AnalysersRecord::read(Node::decode(JsonText::compact($section)));

it('writes each analyser\'s checks, their seconds, and each mutator\'s checks and rejections', function () use ($histories): void {
    expect(JsonText::compact(AnalysersRecord::of($histories)))->toBe(JsonText::compact([
        'phpstan' => ['checks' => 63, 'seconds' => 31.5, 'mutators' => ['Plus' => [60, 12], 'Minus' => [3, 0]]],
        'mago' => ['checks' => 0, 'seconds' => 0.0, 'mutators' => new stdClass()],
    ]))
        ->and(AnalysersRecord::of(AnalyserHistories::none()))->toBe([]);
});

it('reads back what it wrote', function () use ($histories, $read): void {
    expect($read(AnalysersRecord::of($histories)))->toEqual($histories);
});

it('drops an analyser whose time is not well formed, and keeps the rest', function (array $entry) use ($read): void {
    $section = ['phpstan' => $entry, 'mago' => ['checks' => 1, 'seconds' => 0.5, 'mutators' => []]];

    expect([...$read($section)])->toEqual([AnalyserHistory::of('mago')->withTime(CheckTime::of(1, Seconds::of(0.5)))]);
})->with([
    'no checks' => [['seconds' => 1.0, 'mutators' => []]],
    'fewer than none' => [['checks' => -1, 'seconds' => 1.0, 'mutators' => []]],
    'a negative time' => [['checks' => 1, 'seconds' => -1.0, 'mutators' => []]],
    'no time' => [['checks' => 1, 'mutators' => []]],
]);

it('drops a pair that is no count of checks and of no more rejections, and keeps the rest', function (mixed $pair) use ($read): void {
    $section = ['phpstan' => ['checks' => 9, 'seconds' => 1.0, 'mutators' => ['Bad' => $pair, 'Plus' => [9, 1]]]];

    $phpstan = AnalyserIdentity::of('phpstan', '2.1.30', Digest::of(str_repeat('a', 64)));

    expect([...$read($section)->of($phpstan)])->toEqual([RejectionRate::of('Plus', 9, 1)]);
})->with([
    'more rejections than checks' => [[1, 2]],
    'negative rejections' => [[1, -1]],
    'one number' => [[1]],
    'three numbers' => [[3, 1, 0]],
    'text' => [['3', '1']],
    'no list' => ['3/1'],
    'a map' => [['checks' => 3, 'rejections' => 1]],
]);

it('reads a section that is missing or no map as nothing learned', function (mixed $section): void {
    expect([...AnalysersRecord::read(Node::decode(JsonText::compact(['other' => $section]))->field('analysers'))])->toBe([])
        ->and([...AnalysersRecord::read(Node::decode(JsonText::compact(['analysers' => $section]))->field('analysers'))])->toBe([]);
})->with([[[]], ['phpstan'], [3]]);

it('reads an analyser or a mutator whose name reads as a number as that name', function () use ($read): void {
    $section = ['7' => ['checks' => 2, 'seconds' => 1.0, 'mutators' => ['12' => [2, 1]]]];

    expect([...$read($section)])->toEqual([
        AnalyserHistory::of('7')->withTime(CheckTime::of(2, Seconds::of(1.0)))->withRate(RejectionRate::of('12', 2, 1)),
    ]);
});

it('keeps a pair of as many rejections as checks, and of none of either', function () use ($read): void {
    $section = ['phpstan' => ['checks' => 2, 'seconds' => 1.0, 'mutators' => ['Plus' => [2, 2], 'Minus' => [0, 0]]]];
    $phpstan = AnalyserIdentity::of('phpstan', '2.1.30', Digest::of(str_repeat('a', 64)));

    expect([...$read($section)->of($phpstan)])->toEqual([RejectionRate::of('Plus', 2, 2), RejectionRate::of('Minus', 0, 0)]);
});
