<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPace;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSlowdown;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamStart;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamWatch;
use NightWorksIO\MutationGate\Adapter\Pest\Split\WatchedStream;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/** A process of this PHP running this code, started, and once it forks, its child forked. */
function watchedProcess(string $code): Process
{
    $process = new Process([PHP_BINARY, '-r', $code]);
    $process->start();
    $forks = str_contains($code, 'pcntl_fork');
    ServedProjects::until(static fn(): bool => ! $forks || shell_exec(sprintf('pgrep -P %d', (int) $process->getPid())) !== null);

    return $process;
}

it('stops a stream whose parent passed its deadline, with what it started, and starts one in its place of the tests it had not ended but that one', function (): void {
    $directory = Scratch::directory();
    $results = sprintf('%s/results-1-0.jsonl', $directory);
    file_put_contents($results, StreamLine::parent('a', passed: true, seconds: 0.1));
    $stuck = watchedProcess('pcntl_fork(); sleep(60);');
    file_put_contents(sprintf('%s/live-%d', $directory, $stuck->getPid()), '{"t": "stuck", "d": 0, "s": 3.5}');
    $left = [];
    $slowed = 0.0;
    $again = static function (array $tests, float $slowdown) use (&$left, &$slowed, $directory): WatchedStream {
        $left = $tests;
        $slowed = $slowdown;

        return new WatchedStream(watchedProcess(''), sprintf('%s/results-1-2.jsonl', $directory), [], static fn(): NotGiven => NotGiven::value());
    };
    $started = microtime(as_float: true);

    $wrote = new StreamWatch($directory, new StreamSlowdown())->ended([new WatchedStream($stuck, $results, ['a', 'stuck', 'b'], $again)]);

    expect([$wrote, $left, $slowed, $stuck->isRunning()])->toBe([[$results, sprintf('%s/results-1-2.jsonl', $directory)], ['b'], 3.5, false])
        ->and(microtime(as_float: true) - $started)->toBeLessThan(30.0)
        ->and(glob(sprintf('%s/live-*', $directory)))->toBe([])
        ->and(ServedProjects::until(static fn(): bool => shell_exec(sprintf('pgrep -f %s', escapeshellarg('pcntl_fork(); sleep(60);'))) === null))->toBeTrue();
});

it('waits for a stream within its deadline, takes away the mark one leaves, and starts none in the place of one that ran whole files or ended every test', function (): void {
    $directory = Scratch::directory();
    $started = [];
    $never = static function (array $tests) use (&$started): NotGiven {
        $started[] = $tests;

        return NotGiven::value();
    };
    $running = new WatchedStream(watchedProcess('usleep(1000000);'), 'running', ['slow'], $never);
    file_put_contents(sprintf('%s/live-%d', $directory, (int) $running->pid), sprintf('{"t": "slow", "d": %F}', microtime(as_float: true) + 60.0));
    $whole = new WatchedStream(watchedProcess('pcntl_fork(); sleep(60);'), 'whole', [], $never);
    file_put_contents(sprintf('%s/live-%d', $directory, (int) $whole->pid), '{"t": "stuck", "d": 0}');
    $ended = sprintf('%s/results-1-2.jsonl', $directory);
    file_put_contents($ended, StreamLine::parent('a', passed: true, seconds: 0.1));
    $done = new WatchedStream(watchedProcess('pcntl_fork(); sleep(60);'), $ended, ['a'], $never);
    file_put_contents(sprintf('%s/live-%d', $directory, (int) $done->pid), '{"t": "a", "d": 0}');

    $wrote = new StreamWatch($directory, new StreamSlowdown())->ended([$running, $whole, $done]);

    expect($wrote)->toEqualCanonicalizing(['running', 'whole', $ended])
        ->and($started)->toBe([])
        ->and($running->process->getExitCode())->toBe(0)
        ->and(glob(sprintf('%s/live-*', $directory)))->toBe([]);
});

it('starts a stream in a place one round of streams on, so its files are its own, at the slowdown it is given', function (): void {
    $next = new StreamStart(2, 1, 4, [], ['t' => 1.0], 8, ['--x'], keepsPcov: true)->next(2.0);

    expect([$next->pass, $next->at, $next->round, $next->times, $next->cores, $next->selecting, $next->keepsPcov, $next->slowdown])
        ->toBe([2, 5, 4, ['t' => 1.0], 8, ['--x'], true, 2.0])
        ->and($next->next(4.0)->at)->toBe(9);
});

it('starts a stream in a stopped one\'s place at the backoff times the slowdown that one reached, never under as fast as the map', function (): void {
    expect([
        new StreamPace(on: true)->after(1.0),
        new StreamPace(on: true)->after(3.0),
        new StreamPace(on: true, backoff: 1.0)->after(3.0),
        new StreamPace(on: true, backoff: 3.0)->after(0.5),
        new StreamPace(on: true, backoff: 0.5)->after(2.0),
    ])->toBe([2.0, 6.0, 3.0, 3.0, 2.0]);
});

/**
 * The code of a stream that runs until the stream of this name has started, then ends, or ends within ten seconds where
 * none starts: it runs on as long as the test needs it to, and never past a watch that starts too few.
 */
function watchedUntilStarted(string $directory, string $name): string
{
    return sprintf(
        '$f = %s; $end = microtime(true) + 10; while (! is_file($f) && microtime(true) < $end) { usleep(10000); }',
        var_export(sprintf('%s/%s-started', $directory, $name), return: true),
    );
}

it('runs no more queued streams at once than it is given, starting the next, in the queue\'s order, as one ends', function (): void {
    $directory = Scratch::directory();
    $never = static fn(): NotGiven => NotGiven::value();
    $started = [];
    $most = 0;
    $live = [];
    $queued = [];
    $codes = [
        'first' => watchedUntilStarted($directory, 'second'),
        'second' => watchedUntilStarted($directory, 'third'),
        'third' => '',
    ];

    foreach ($codes as $name => $code) {
        $queued[] = static function () use ($name, $code, $directory, $never, &$started, &$most, &$live): WatchedStream {
            $live = array_values(array_filter($live, static fn(Process $process): bool => $process->isRunning()));
            $process = watchedProcess($code);
            $live[] = $process;
            $started[] = $name;
            $most = max($most, count($live));
            touch(sprintf('%s/%s-started', $directory, $name));

            return new WatchedStream($process, $name, [], $never);
        };
    }

    $wrote = new StreamWatch($directory, new StreamSlowdown())->ended($queued, 2);

    expect($wrote)->toEqualCanonicalizing(['first', 'second', 'third'])
        ->and([$started, $most])->toBe([['first', 'second', 'third'], 2]);
});
