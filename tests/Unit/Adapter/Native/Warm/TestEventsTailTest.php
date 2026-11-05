<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Warm\TestEventsTail;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\GrowingEvents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * What a reader of this growing file says at each step.
 *
 * @param  list<array{string, float}> $steps
 * @return list<string>
 */
function readOfTestEventsTail(GrowingEvents $events, array $steps): array
{
    $reader = TestEventsTail::of($events->watched());

    return $reader instanceof TestEventsTail
        ? $events->read($reader->overrun(...), $steps)
        : throw new LogicException('A watched run has a reader.');
}

it('says a test overran once it has run past its own limit since its start line was read, and nothing before', function (): void {
    $events = GrowingEvents::at(Scratch::directory());

    expect(readOfTestEventsTail($events, [
        [sprintf("%s\n", GrowingEvents::started(GrowingEvents::TEST)), 100.0],
        ['', 102.5],
        ['', 104.0],
    ]))->toBe(['-', '-', GrowingEvents::TEST]);
});

it('reads each byte once, as the file grows, a start line written in two parts read whole', function (): void {
    $events = GrowingEvents::at(Scratch::directory());

    expect(readOfTestEventsTail($events, [
        [GrowingEvents::started('Money::ad'), 100.0],
        ["ds\n", 101.0],
        ['', 103.5],
        ['', 104.5],
    ]))->toBe(['-', '-', '-', GrowingEvents::TEST]);
});

it('reads nothing of a file not yet written, and the whole file once it is', function (): void {
    $directory = Scratch::directory();
    $events = GrowingEvents::at($directory);
    $reader = TestEventsTail::of($events->watched());
    $before = $reader instanceof TestEventsTail ? $reader->overrun(100.0) : $reader;
    $events->grown(sprintf("%s\n", GrowingEvents::started(GrowingEvents::TEST)));

    $later = $reader instanceof TestEventsTail ? [$reader->overrun(101.0), $reader->overrun(105.0)] : [];

    expect($before)->toEqual(NotGiven::value())
        ->and($later)->toEqual([NotGiven::value(), Overrun::of(TestId::of(GrowingEvents::TEST), Seconds::of(3.0))]);
});


it('has no reader where the run watches no test, or has no silence limit', function (): void {
    expect([TestEventsTail::of(SilenceLimit::of(Seconds::of(9.0), 'events.txt')), TestEventsTail::of(NotGiven::value())])
        ->toEqual([NotGiven::value(), NotGiven::value()]);
});
