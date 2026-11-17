<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamProgress;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('counts each mutant a child killed, ended or timed out in any stream once, and none a child passed', function (): void {
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
    StreamProgress::named($results, $streams);

    expect(StreamProgress::decided($results))->toBe(3);

    StreamProgress::unnamed($results);

    expect(StreamProgress::decided($results))->toBe(0)
        ->and(file_exists(sprintf('%s.streams', $results)))->toBeFalse();
});

it('counts none where the run names no file, or no stream directory beside its results', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    StreamProgress::named(results: false, directory: Scratch::directory());
    StreamProgress::named('', Scratch::directory());
    StreamProgress::unnamed(results: false);

    expect(StreamProgress::decided($results))->toBe(0)
        ->and(glob(sprintf('%s/*', dirname($results))))->toBe([]);
});
