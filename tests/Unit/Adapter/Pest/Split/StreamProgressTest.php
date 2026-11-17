<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamProgress;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamsSeen;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('counts each mutant a child killed, ended or timed out in any stream once, and none a child passed, and names the latest pass and how many of its streams run', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $streams = Scratch::directory();
    file_put_contents(sprintf('%s/results-1-0.jsonl', $streams), implode('', [
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::child('a', 'T::one', failed: true, seconds: 0.1),
        StreamLine::child('b', 'T::one', failed: false, seconds: 0.1),
        StreamLine::endedChild('c', 'T::one', 255),
    ]));
    file_put_contents(sprintf('%s/results-2-1.jsonl', $streams), implode('', [
        StreamLine::timedOut('d', 'T::two'),
        StreamLine::child('a', 'T::two', failed: true, seconds: 0.1),
        "not a line\n",
    ]));
    file_put_contents(sprintf('%s/results-2-0.jsonl', $streams), '');
    touch(sprintf('%s/live-1', $streams));
    StreamProgress::named($results, $streams);
    $seen = StreamProgress::seen($results);

    expect([$seen->decided, $seen->areStreaming(), $seen->phase()])->toBe([3, true, 'streams pass 2: 1 of 2 running']);

    StreamProgress::unnamed($results);
    $gone = StreamProgress::seen($results);

    expect([$gone->decided, $gone->areStreaming(), $gone->phase()])->toBe([0, false, ''])
        ->and(file_exists(sprintf('%s.streams', $results)))->toBeFalse();
});

it('counts none where the run names no file, or no stream directory beside its results', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    StreamProgress::named(results: false, directory: Scratch::directory());
    StreamProgress::named('', Scratch::directory());
    StreamProgress::unnamed(results: false);

    expect(StreamProgress::seen($results))->toEqual(StreamsSeen::none())
        ->and(glob(sprintf('%s/*', dirname($results))))->toBe([]);
});
