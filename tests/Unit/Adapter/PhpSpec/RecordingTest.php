<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\Recording;
use NightWorksIO\MutationGate\Core\Native\Selected;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\PhpSpecExamples;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PhpSpec\Event\ExampleEvent;

afterEach(function (): void {
    Scratch::sweep();
});

/** What a recording of the selected example of HeldSpec appends, for each of these results, to its file. */
function recorded(int ...$results): string
{
    $file = sprintf('%s/results.txt', Scratch::directory());
    $selected = Selected::narrowedTo(TestIds::of(TestId::of(PhpSpecExamples::id('it_is_held_by_its_spec'))), NotGiven::value(), NotGiven::value(), NotGiven::value());
    $recording = new Recording($file, $selected);

    foreach ($results as $result) {
        foreach (['it_is_held_by_its_spec', 'it_is_held_by_its_own_path_too'] as $method) {
            $recording->started(PhpSpecExamples::event($method));
            $recording->ended(PhpSpecExamples::event($method, $result));
        }
    }

    return is_file($file) ? (string) file_get_contents($file) : '';
}

it('listens for each example\'s start and end', function (): void {
    expect(Recording::getSubscribedEvents())->toBe(['beforeExample' => 'started', 'afterExample' => 'ended']);
});

it('records a selected example as it starts and as it ends, and nothing of one the run does not select', function (int $result, string $outcome): void {
    $id = rawurlencode(PhpSpecExamples::id('it_is_held_by_its_spec'));

    expect(recorded($result))->toBe(sprintf("started %s\n%s %s\n", $id, $outcome, $id));
})->with([
    'passed' => [ExampleEvent::PASSED, 'passed'],
    'failed' => [ExampleEvent::FAILED, 'failed'],
    'broken, which errored' => [ExampleEvent::BROKEN, 'errored'],
    'skipped' => [ExampleEvent::SKIPPED, 'neither'],
    'pending' => [ExampleEvent::PENDING, 'neither'],
]);

it('appends each example it records to what the file holds', function (): void {
    $id = rawurlencode(PhpSpecExamples::id('it_is_held_by_its_spec'));

    expect(recorded(ExampleEvent::FAILED, ExampleEvent::PASSED))
        ->toBe(sprintf("started %s\nfailed %s\nstarted %s\npassed %s\n", $id, $id, $id, $id));
});
