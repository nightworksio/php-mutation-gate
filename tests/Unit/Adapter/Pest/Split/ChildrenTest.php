<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Children;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;
use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\Listed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('takes no child it did not fork, and drops each copy a child still running holds as its process ends', function (): void {
    $directory = Scratch::directory();
    $file = sprintf('%s/main.sqlite', $directory);
    touch($file);

    $answer = json_decode(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Children;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;
        use NightWorksIO\MutationGate\Tests\Support\LaravelTesting;

        $manager = LaravelTesting::managing(['main' => ['driver' => 'sqlite', 'database' => %1$s]]);
        $manager->getConnections()['main']->getPdo()->exec('SELECT 1');
        $copies = Copies::taken(Descriptors::held(), %2$s);
        $children = new Children(new StreamPlan(%2$s . '/stream.jsonl', [], [], [], ChildPlaces::fixed(1), new ChildLimit(1.0, 1.0, 10.0)), new \NightWorksIO\MutationGate\Adapter\Process\LiveProcesses());
        $other = pcntl_fork();

        if ($other === 0) {
            exit(0);
        }

        usleep(300000);
        $reaped = $children->reaped(blocking: false);
        $pid = $children->forked('m1', 't1', $copies instanceof Copies ? $copies : Copies::none());

        if ($pid === 0) {
            usleep(300000);
            exit(0);
        }

        echo json_encode([$copies instanceof Copies, $reaped, $children->areRunning(), count(glob(%2$s . '/*.sqlite'))]);
        PHP, var_export($file, return: true), var_export($directory, return: true))), associative: true);

    expect($answer)->toBe([true, [], true, 2])
        ->and(glob(sprintf('%s/*.sqlite', $directory)))->toBe([$file]);
});

/**
 * What a program that forks children of test t1 under these limits printed,
 * the Split classes it names imported and $results the stream's results.
 */
function childrenAnswer(string $program, int $places, float $multiple): string
{
    return FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Children;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;

        $results = %s . '/stream.jsonl';
        $children = new Children(new StreamPlan($results, [], [], ['t1' => 0.01], ChildPlaces::fixed(%d), new ChildLimit(%F, 0.2, 30.0)), new \NightWorksIO\MutationGate\Adapter\Process\LiveProcesses());
        $since = static fn(int $from): float => (hrtime(true) - $from) / 1e9;
        $written = static fn(): string => is_file($results) ? (string) file_get_contents($results) : '';
        %s
        PHP, var_export(Scratch::directory(), return: true), $places, $multiple, $program));
}

it('stops no child as late while its parent still runs the test, however long the map says the test takes', function (): void {
    expect(childrenAnswer(<<<'PHP'
        $began = hrtime(true);

        if ($children->forked('m1', 't1', Copies::none()) === 0) {
            usleep(600000);
            posix_kill(getmypid(), SIGKILL);
        }

        for ($i = 0; $i < 8; $i++) {
            usleep(100000);
            $children->reaped(blocking: false);
        }

        $children->ended('t1', $since($began));
        echo json_encode([$children->reapedAll(), $written()]);
        PHP, places: 2, multiple: 1.0))->toBe('[[],""]');
});

it('times a child out once its parent ended the test in less than the child runs', function (): void {
    expect(childrenAnswer(<<<'PHP'
        if ($children->forked('m1', 't1', Copies::none()) === 0) {
            while (true) {
                usleep(1000);
            }
        }

        $children->ended('t1', 0.01);
        echo json_encode([$children->reapedAll(), $written()]);
        PHP, places: 2, multiple: 1.0))->toBe(json_encode([[], StreamLine::timedOut('m1', 't1')]));
});

it('lets a child past the map\'s time out of its place where its parent needs it, and times it out against the parent\'s own time less its wait', function (): void {
    $answer = childrenAnswer(<<<'PHP'
        $began = hrtime(true);
        $hung = $children->forked('m1', 't1', Copies::none());

        if ($hung === 0) {
            while (true) {
                usleep(1000);
            }
        }

        if ($children->forked('m2', 't1', Copies::none()) === 0) {
            posix_kill(getmypid(), SIGKILL);
        }

        $children->ended('t1', $since($began));
        $failed = $children->reapedAll();
        printf('%s %d %s', json_encode([$failed, $written(), posix_kill($hung, 0)]), $since($began) < 3.0, $since($began));
        PHP, places: 1, multiple: 30.0);

    expect($answer)->toStartWith(sprintf('%s 1 ', json_encode([[], StreamLine::timedOut('m1', 't1'), false])));
});

it('lets a child out of its place run on where the parent\'s own time of the test, on a loaded machine, turns out longer than the map\'s', function (): void {
    expect(childrenAnswer(<<<'PHP'
        $began = hrtime(true);

        if ($children->forked('m1', 't1', Copies::none()) === 0) {
            for ($i = 0; $i < 40; $i++) {
                usleep(10000);
            }

            posix_kill(getmypid(), SIGKILL);
        }

        if ($children->forked('m2', 't1', Copies::none()) === 0) {
            posix_kill(getmypid(), SIGKILL);
        }

        for ($i = 0; $i < 20; $i++) {
            usleep(100000);
            $children->reaped(blocking: false);
        }

        $children->ended('t1', $since($began));
        echo json_encode([$children->reapedAll(), $written()]);
        PHP, places: 1, multiple: 1.0))->toBe('[[],""]');
});

it('lists the processes running only once a child is late, never at a look that finds none', function (): void {
    $listings = 0;
    $children = new Children(
        new StreamPlan(sprintf('%s/stream.jsonl', Scratch::directory()), [], [], [], ChildPlaces::fixed(1), new ChildLimit(1.0, 1.0, 10.0)),
        new Listed(static function () use (&$listings): ProcessListing {
            $listings++;

            return ProcessListing::printed('');
        }),
    );

    expect($children->reaped(blocking: false))->toBe([])
        ->and($children->reapedAll())->toBe([])
        ->and($listings)->toBe(0);
});
