<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ParentDeadline;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$planned = static fn(bool $deadline): StreamPlan => new StreamPlan(
    sprintf('%s/results.jsonl', Scratch::directory()),
    [],
    [],
    ['timed' => 2.0],
    ChildPlaces::fixed(1),
    new ChildLimit(3.0, 1.0, 300.0),
    deadline: $deadline,
);

it('keeps the limit a child of the test is held to, of the map\'s time, or timeouts.most where it timed none, and passes it only once that time is gone', function () use ($planned): void {
    $mark = sprintf('%s/live-1', Scratch::directory());
    $deadline = ParentDeadline::of($planned(deadline: true), $mark);
    $now = microtime(as_float: true);

    $deadline->began('timed');
    $timed = [ParentDeadline::passedIn($mark, $now + 5.5), ParentDeadline::passedIn($mark, $now + 6.5)];
    $deadline->began('untimed');

    expect($timed)->toBe([false, 'timed'])
        ->and(ParentDeadline::passedIn($mark, $now + 299.0))->toBeFalse()
        ->and(ParentDeadline::passedIn($mark, $now + 301.0))->toBe('untimed');
});

it('moves the deadline on by each wait for a child\'s place, and keeps none once the test ended', function () use ($planned): void {
    $mark = sprintf('%s/live-1', Scratch::directory());
    touch($mark);
    $deadline = ParentDeadline::of($planned(deadline: true), $mark);
    $now = microtime(as_float: true);

    $deadline->began('timed');
    $deadline->waited(10.0);
    $deadline->waited(-4.0);
    $waited = ParentDeadline::passedIn($mark, $now + 15.5);
    $deadline->ended();
    $deadline->waited(10.0);

    expect([$waited, ParentDeadline::passedIn($mark, $now + 16.5)])->toBe([false, false])
        ->and(file_get_contents($mark))->toBe('');
});

it('keeps nothing where the plan sets no deadline, or the stream has no mark, and reads none from a mark that holds none', function () use ($planned): void {
    $mark = sprintf('%s/live-1', Scratch::directory());
    ParentDeadline::of($planned(deadline: false), $mark)->began('timed');
    ParentDeadline::of($planned(deadline: true), '')->began('timed');
    file_put_contents(sprintf('%s/live-2', Scratch::directory()), '{"t": 1, "d": 0}');
    file_put_contents(sprintf('%s/live-3', Scratch::directory()), '{"t": "a", "d": "0"}');

    expect(file_exists($mark))->toBeFalse()
        ->and(ParentDeadline::passedIn($mark, PHP_FLOAT_MAX))->toBeFalse()
        ->and(ParentDeadline::passedIn(sprintf('%s/live-2', Scratch::directory()), PHP_FLOAT_MAX))->toBeFalse()
        ->and(ParentDeadline::passedIn(sprintf('%s/live-3', Scratch::directory()), PHP_FLOAT_MAX))->toBeFalse();
});

it('names the mark of a stream by its process id in its directory, and none where it marks none', function (): void {
    $directory = Scratch::directory();

    expect(ChildPlaces::markOf($directory, 42))->toBe(sprintf('%s/live-42', $directory))
        ->and(ChildPlaces::fixed(1, $directory)->mark())->toBe(sprintf('%s/live-%d', $directory, getmypid()))
        ->and(ChildPlaces::fixed(1)->mark())->toBe('');
});
