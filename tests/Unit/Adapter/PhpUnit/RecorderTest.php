<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Recorder;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Each line a recorder wrote, with the test's id cut to what tells the tests apart.
 *
 * @return list<string>
 */
function recordedLines(string $results): array
{
    $lines = file($results, FILE_IGNORE_NEW_LINES);

    return array_map(
        static fn(string $line): string => sprintf('%s', preg_replace('/ P%5CTests%5C.*$/', ' <test>', $line)),
        is_array($lines) ? $lines : [],
    );
}

it('records a test as it starts, and as it finishes how it ended: failed, errored, passed, or neither', function (Closure $ending, string $recorded): void {
    $results = sprintf('%s/results.txt', Scratch::directory());
    $events = new Facade();
    Recorder::listening($results, $events);

    PhpUnitEvents::started($events);
    $ending($events);
    PhpUnitEvents::finished($events);

    expect(recordedLines($results))->toBe(['started <test>', sprintf('%s <test>', $recorded)]);
})->with([
    'a failure' => [PhpUnitEvents::failed(...), 'failed'],
    'an error' => [PhpUnitEvents::errored(...), 'errored'],
    'a pass' => [PhpUnitEvents::passed(...), 'passed'],
    'a skip' => [static fn(Facade $events): Facade => $events, 'neither'],
]);

it('writes no test\'s outcome against the next test to start', function (): void {
    $results = sprintf('%s/results.txt', Scratch::directory());
    $events = new Facade();
    Recorder::listening($results, $events);

    PhpUnitEvents::errored($events);
    PhpUnitEvents::started($events);
    PhpUnitEvents::finished($events);
    PhpUnitEvents::started($events);
    PhpUnitEvents::failed($events);
    PhpUnitEvents::finished($events);
    PhpUnitEvents::started($events);
    PhpUnitEvents::finished($events);

    expect(recordedLines($results))
        ->toBe(['started <test>', 'neither <test>', 'started <test>', 'failed <test>', 'started <test>', 'neither <test>']);
});

it('records nothing where no results file is named, or PHPUnit takes no more subscribers', function (): void {
    $sealed = new Facade();
    $sealed->seal();
    $results = sprintf('%s/results.txt', Scratch::directory());

    Recorder::listening(results: false, events: new Facade());
    Recorder::listening('', new Facade());
    Recorder::listening($results, $sealed);

    expect(is_file($results))->toBeFalse();
});
