<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Recorded;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Recorder;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;

afterEach(function (): void {
    Scratch::sweep();
});


it('records a test that failed, errored or passed as it finishes, and a test that did neither as neither', function (Closure $ending, string $recorded): void {
    $results = sprintf('%s/results.txt', Scratch::directory());
    $events = new Facade();
    Recorder::listening($results, $events);

    $ending($events);
    PhpUnitEvents::finished($events);

    expect((string) file_get_contents($results))->toStartWith(sprintf('%s P%%5CTests%%5CUnit', $recorded))
        ->and(substr_count((string) file_get_contents($results), "\n"))->toBe(1);
})->with([
    'a failure' => [PhpUnitEvents::failed(...), 'failed'],
    'an error' => [PhpUnitEvents::errored(...), 'errored'],
    'a pass' => [PhpUnitEvents::passed(...), 'passed'],
    'a skip' => [static fn(Facade $events): Facade => $events, 'neither'],
]);

it('reads back what it recorded, as the killers and whether a test ran', function (): void {
    $results = sprintf('%s/results.txt', Scratch::directory());
    $events = new Facade();
    Recorder::listening($results, $events);

    PhpUnitEvents::failed($events);
    PhpUnitEvents::finished($events);
    $recorded = Recorded::in($results);

    expect($recorded->ranAny())->toBeTrue()
        ->and(count($recorded->killers()))->toBe(1)
        ->and(array_map(static fn(TestId $test): string => $test->value(), [...$recorded->killers()])[0])
        ->toStartWith('P\\Tests\\Unit\\Adapter\\PhpUnit\\RecorderTest::');
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
