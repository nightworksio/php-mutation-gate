<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\TailStarted;
use NightWorksIO\MutationGate\Adapter\Pest\TailWorkers;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StartedFake;

afterEach(function (): void {
    Scratch::sweep();
});

it('ends the tail\'s workers and clears their directory once its runs are awaited or stopped', function (bool $awaits): void {
    $beside = sprintf('%s/tail.jsonl', Scratch::directory());
    $workers = new TailWorkers(WorkerSlots::alone(), $beside, NotGiven::value(), NotGiven::value(), Seconds::of(5.0));
    $workers->besideFor(NotGiven::value(), []);
    $ends = ProcessEnds::of(Ran::finished(succeeded: true, output: 'ran'));
    $runs = new StartedFake($ends);
    $made = is_dir(WarmDirectory::beside($beside));
    $started = TailStarted::of($runs, $workers);

    $ended = $awaits ? $started->ends() : NotGiven::value();

    if (! $awaits) {
        $started->stop();
    }

    expect($made)->toBeTrue()
        ->and($ended)->toEqual($awaits ? $ends : NotGiven::value())
        ->and($runs->handled())->toBe([$awaits, ! $awaits])
        ->and(is_dir(WarmDirectory::beside($beside)))->toBeFalse();
})->with([
    'awaited' => [true],
    'stopped' => [false],
]);
