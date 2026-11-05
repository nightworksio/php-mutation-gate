<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\TailWorkers;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\BootSpec;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\OpcacheBlacklist;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The process id each seat of a set's directory wrote, by its place, for the places it holds.
 *
 * @return array<int, string>
 */
function seatsOf(string $beside): array
{
    $directory = WarmDirectory::beside($beside);
    $pids = [];

    foreach ([0, 1, 2, 3] as $place) {
        $pid = Seat::at($directory, $place)->pid();
        $pids += is_file($pid) ? [$place => (string) file_get_contents($pid)] : [];
    }

    return $pids;
}

it('starts one set a worker in each slot, named beside the tail\'s results, and the same set again for the same suites and variables', function (): void {
    $beside = sprintf('%s/tail.jsonl', Scratch::directory());
    $workers = new TailWorkers(WorkerSlots::of(ProcessCount::of(2), 'run'), $beside, NotGiven::value(), NotGiven::value());

    try {
        $first = $workers->besideFor(NotGiven::value(), []);
        $seats = seatsOf($beside);
        $again = $workers->besideFor(NotGiven::value(), []);

        expect([$first, $again])->toBe([$beside, $beside])
            ->and(array_keys($seats))->toBe([1, 2])
            ->and(seatsOf($beside))->toBe($seats);
    } finally {
        $workers->cleared();
    }
});

it('starts a set of its own, named beside the first by its place, for other suites or other variables', function (): void {
    $beside = sprintf('%s/tail.jsonl', Scratch::directory());
    $workers = new TailWorkers(WorkerSlots::alone(), $beside, NotGiven::value(), NotGiven::value());

    try {
        $named = [
            $workers->besideFor(NotGiven::value(), []),
            $workers->besideFor(['Unit'], []),
            $workers->besideFor(NotGiven::value(), ['APP_ENV' => 'testing']),
            $workers->besideFor(['Unit'], ['APP_ENV' => 'testing']),
            $workers->besideFor(['Unit'], []),
        ];

        expect($named)->toBe([$beside, sprintf('%s-1', $beside), sprintf('%s-2', $beside), sprintf('%s-3', $beside), sprintf('%s-1', $beside)]);
    } finally {
        $workers->cleared();
    }
});

it('clears what an earlier run left before it starts a set, and every set\'s directory once cleared', function (): void {
    $beside = sprintf('%s/tail.jsonl', Scratch::directory());
    $left = WarmDirectory::made($beside);
    file_put_contents(sprintf('%s/stale', $left), 'from before');
    $workers = new TailWorkers(WorkerSlots::alone(), $beside, NotGiven::value(), NotGiven::value());
    $workers->besideFor(NotGiven::value(), []);
    $workers->besideFor(['Unit'], []);
    $stale = is_file(sprintf('%s/stale', $left));
    $workers->cleared();

    expect($stale)->toBeFalse()
        ->and([is_dir(WarmDirectory::beside($beside)), is_dir(WarmDirectory::beside(sprintf('%s-1', $beside)))])->toBe([false, false]);
});

it('leaves its blacklist in each set\'s directory where its workers compile through opcache, and what the tail boots where it boots', function (): void {
    $root = Scratch::directory();
    $project = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $uncached = OpcacheBlacklist::of($project, MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $beside = sprintf('%s/tail.jsonl', Scratch::directory());
    $workers = new TailWorkers(WorkerSlots::alone(), $beside, $project, $uncached);

    try {
        $workers->besideFor(NotGiven::value(), []);
        $workers->besideFor(['Unit'], []);
        $unbooted = WarmDirectory::beside($beside);
        $booted = WarmDirectory::beside(sprintf('%s-1', $beside));
        $spec = BootSpec::readIn($booted);

        expect([OpcacheBlacklist::isIn($unbooted), OpcacheBlacklist::isIn($booted)])->toBe([true, true])
            ->and(BootSpec::readIn($unbooted))->toEqual(NotGiven::value())
            ->and($spec instanceof BootSpec ? $spec->suites() : [])->toBe(['Unit']);
    } finally {
        $workers->cleared();
    }
});
