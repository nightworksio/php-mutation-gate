<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
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
    file_put_contents(sprintf('%s/live-%d', $directory, $stuck->getPid()), '{"t": "stuck", "d": 0}');
    $left = [];
    $again = static function (array $tests) use (&$left, $directory): WatchedStream {
        $left = $tests;

        return new WatchedStream(watchedProcess(''), sprintf('%s/results-1-2.jsonl', $directory), [], static fn(): NotGiven => NotGiven::value());
    };
    $started = microtime(as_float: true);

    $wrote = new StreamWatch($directory)->ended([new WatchedStream($stuck, $results, ['a', 'stuck', 'b'], $again)]);

    expect([$wrote, $left, $stuck->isRunning()])->toBe([[$results, sprintf('%s/results-1-2.jsonl', $directory)], ['b'], false])
        ->and(microtime(as_float: true) - $started)->toBeLessThan(30.0)
        ->and(glob(sprintf('%s/live-*', $directory)))->toBe([])
        ->and(shell_exec(sprintf('pgrep -f %s', escapeshellarg('pcntl_fork(); sleep(60);'))))->toBeNull();
});

it('waits for a stream within its deadline, takes away the mark one leaves, and starts none in the place of one that ran whole files or ended every test', function (): void {
    $directory = Scratch::directory();
    $running = watchedProcess('usleep(300000);');
    file_put_contents(sprintf('%s/live-%d', $directory, $running->getPid()), sprintf('{"t": "slow", "d": %F}', microtime(as_float: true) + 60.0));
    $started = [];
    $never = static function (array $tests) use (&$started): NotGiven {
        $started[] = $tests;

        return NotGiven::value();
    };
    $whole = watchedProcess('pcntl_fork(); sleep(60);');
    file_put_contents(sprintf('%s/live-%d', $directory, $whole->getPid()), '{"t": "stuck", "d": 0}');
    $done = watchedProcess('pcntl_fork(); sleep(60);');
    $ended = sprintf('%s/results-1-2.jsonl', $directory);
    file_put_contents($ended, StreamLine::parent('a', passed: true, seconds: 0.1));
    file_put_contents(sprintf('%s/live-%d', $directory, $done->getPid()), '{"t": "a", "d": 0}');

    $wrote = new StreamWatch($directory)->ended([
        new WatchedStream($running, 'running', ['slow'], $never),
        new WatchedStream($whole, 'whole', [], $never),
        new WatchedStream($done, $ended, ['a'], $never),
    ]);

    expect($wrote)->toEqualCanonicalizing(['running', 'whole', $ended])
        ->and($started)->toBe([])
        ->and($running->getExitCode())->toBe(0)
        ->and(glob(sprintf('%s/live-*', $directory)))->toBe([]);
});

it('starts a stream in a place one round of streams on, so its files are its own', function (): void {
    $next = new StreamStart(2, 1, 4, [], ['t' => 1.0], 8, ['--x'])->next();

    expect([$next->pass, $next->at, $next->round, $next->times, $next->cores, $next->selecting])
        ->toBe([2, 5, 4, ['t' => 1.0], 8, ['--x']])
        ->and($next->next()->at)->toBe(9);
});
