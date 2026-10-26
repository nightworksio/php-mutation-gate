<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Request;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

$holds = [
    'holds:src/Adapter/Pest/Warm/Worker.php',
    'holds:src/Adapter/Pest/Warm/Client.php',
    'holds:src/Adapter/Pest/Warm/Pool.php',
    'holds:src/Adapter/Pest/Warm/Request.php',
    'holds:src/Adapter/Pest/Warm/Finish.php',
    'holds:src/Adapter/Pest/Warm/Offsets.php',
    'holds:src/Adapter/Pest/Warm/Seat.php',
    'holds:src/Adapter/Pest/Warm/WarmDirectory.php',
    'holds:src/Adapter/Pest/Warm/GateClasses.php',
    'holds:src/Adapter/Pest/Warm/WholeTree.php',
];

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
    Scratch::sweep();
});

/**
 * The fixture run through place 1's warm worker of the run that records to
 * these results, ending as told, with these variables too, not yet started.
 *
 * @param array<string, string> $variables
 */
function warmRun(string $results, string $end, array $variables = []): Process
{
    putenv(sprintf('%s=%s', GateVariable::Warm->value, WarmDirectory::beside($results)));

    return new Process(
        [...Pool::prefix(1), Tree::at('tests/Fixtures/Warm/run'), '--bail', '--filter=adds'],
        env: ['FIXTURE_END' => $end, ...$variables],
    );
}

/** The process id of place 1's warm worker of the run that records to these results. */
function warmWorker(string $results): int
{
    return (int) file_get_contents(Seat::at(WarmDirectory::beside($results), 1)->pid());
}

/** Whether a process still runs: `ps` lists it, and not as one ended and waiting to be reaped. */
function isThere(int $pid): bool
{
    $asked = new Process(['ps', '-o', 'stat=', '-p', (string) $pid]);
    $asked->run();
    $state = trim($asked->getOutput());

    return $state !== '' && ! str_starts_with($state, 'Z');
}

/** The parent process the fixture run said it had. */
function parentOf(Process $run): int
{
    return preg_match('/parent (\d+)/', $run->getOutput(), $found) === 1 ? (int) $found[1] : 0;
}

it('runs each mutant\'s run in a child its place\'s worker forked, printing what it printed and ending as it ended', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $first = warmRun($results, 'code:3');
    $first->run();
    $second = warmRun($results, 'code:0');
    $second->run();

    expect($first->getExitCode())->toBe(3)
        ->and(fileperms(WarmDirectory::beside($results)) & 0o777)->toBe(0o700)
        ->and(glob(sprintf('%s/1.*-*', WarmDirectory::beside($results))))->toBe([])
        ->and(parentOf($first))->toBe(warmWorker($results))
        ->and($first->getErrorOutput())->toBe("argv --bail --filter=adds\n")
        ->and($second->getExitCode())->toBe(0)
        ->and(parentOf($second))->toBe(warmWorker($results));
})->group(...$holds);

it('ends as the signal that ended its run', function (): void {
    $run = warmRun(sprintf('%s/results.jsonl', Scratch::directory()), 'signal:15');

    expect($run->run(...))->toThrow(ProcessSignaledException::class)
        ->and($run->getTermSignal())->toBe(15);
})->group(...$holds);

it('stops a child once the process waiting on its run is stopped, and serves the next run', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $waiting = warmRun($results, 'wait');
    $waiting->start();
    $waiting->waitUntil(static fn(string $type, string $printed): bool => str_contains($printed, 'parent'));
    $child = preg_match('/run (\d+)/', $waiting->getOutput(), $found) === 1 ? (int) $found[1] : 0;
    $waiting->stop(0);
    $next = warmRun($results, 'code:0');
    $next->run();

    expect($next->getExitCode())->toBe(0)
        ->and(parentOf($next))->toBe(warmWorker($results))
        ->and($child)->toBeGreaterThan(0)
        ->and(isThere($child))->toBeFalse();
})->group(...$holds);

it('starts a run fresh, in place of the client, where its place\'s worker is gone', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    warmRun($results, 'code:0')->run();
    $worker = warmWorker($results);
    $killed = new Process(['kill', '-KILL', (string) $worker]);
    $killed->run();
    $run = warmRun($results, 'code:4');
    $run->run();

    expect($worker)->toBeGreaterThan(0)
        ->and($killed->getExitCode())->toBe(0)
        ->and($run->getExitCode())->toBe(4)
        ->and(parentOf($run))->toBe(getmypid());
})->group(...$holds);

it('starts fresh the run of a mutant of a file the worker loaded, and warns of it once', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $mutated = (string) realpath(Tree::at('src/Adapter/Pest/Warm/Worker.php'));
    $runs = [
        warmRun($results, 'code:0', [Recorder::MUTANT => $mutated]),
        warmRun($results, 'code:0', [Recorder::MUTANT => $mutated]),
    ];
    $runs[0]->run();
    $runs[1]->run();
    $worker = warmWorker($results);

    expect(parentOf($runs[0]))->toBe(getmypid())
        ->and(parentOf($runs[1]))->toBe(getmypid())
        ->and(WarmDirectory::cleared($results))->toEqual(Warnings::of(Warning::that(sprintf(
            'The warm worker had loaded %s, which a mutant changes, so its mutants ran fresh.',
            $mutated,
        ))))
        ->and(is_dir(WarmDirectory::beside($results)))->toBeFalse()
        ->and(isThere($worker))->toBeFalse();
})->group(...$holds);

it('forks no child for a run whose asker is gone before the worker takes it, and serves the next', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    warmRun($results, 'code:0')->run();
    $gone = new Process(['sleep', '0.1']);
    $gone->start();
    $asker = (int) $gone->getPid();
    $gone->wait();
    $seat = Seat::at(WarmDirectory::beside($results), 1);
    Request::of('gone-1', $asker, [Tree::at('tests/Fixtures/Warm/run')], [], Tree::at('tests'))->leftIn($seat->request());
    $next = warmRun($results, 'code:0');
    $next->run();

    expect($asker)->toBeGreaterThan(0)
        ->and(is_file($seat->started('gone-1')) || is_file($seat->ended('gone-1')))->toBeFalse()
        ->and(parentOf($next))->toBe(warmWorker($results));
})->group(...$holds);

it('ends a worker once Pest\'s process is gone', function (): void {
    $directory = sprintf('%s/warm', Scratch::directory());
    $pest = new Process(
        [PHP_BINARY, '-r', sprintf('require %s; %s::prefix(1);', var_export(Tree::at('vendor/autoload.php'), return: true), Pool::class)],
        env: [GateVariable::Warm->value => $directory],
    );
    $pest->mustRun();
    $worker = (string) file_get_contents(Seat::at($directory, 1)->pid());
    $gone = new Process(
        ['sh', '-c', 'for _ in $(seq 100); do kill -0 "$1" 2> /dev/null || exit 0; sleep 0.1; done; exit 1', 'sh', $worker],
    );

    expect($gone->run())->toBe(0);
})->group(...$holds);

it('finds a script Pest names without a directory on the system\'s path, as it starts a run fresh', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    warmRun($results, 'code:0')->run();
    $worker = warmWorker($results);
    new Process(['kill', '-KILL', (string) $worker])->run();
    $fresh = new Process(
        [...Pool::prefix(1), 'run'],
        env: ['FIXTURE_END' => 'code:5', 'PATH' => sprintf('%s:%s', Tree::at('tests/Fixtures/Warm'), getenv('PATH'))],
    );
    $fresh->run();

    expect($fresh->getExitCode())->toBe(5)
        ->and(parentOf($fresh))->toBe(getmypid());
})->group(...$holds);

it('stops the child of a run whose worker went before it ended, and starts the run fresh', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $waiting = warmRun($results, 'wait');
    $waiting->start();
    $waiting->waitUntil(static fn(string $type, string $printed): bool => str_contains($printed, 'parent'));
    $child = preg_match('/run (\d+)/', $waiting->getOutput(), $found) === 1 ? (int) $found[1] : 0;
    new Process(['kill', '-KILL', (string) warmWorker($results)])->run();
    $waiting->waitUntil(static fn(string $type, string $printed): bool => str_contains($printed, sprintf('parent %d', getmypid())));
    $orphaned = isThere($child);
    $waiting->stop(0);

    expect($child)->toBeGreaterThan(0)
        ->and($orphaned)->toBeFalse();
})->group(...$holds);

it('starts a run fresh, and stops nothing, where its worker went before forking it a child', function (): void {
    $directory = sprintf('%s/warm', Scratch::directory());
    mkdir($directory);
    $standIn = new Process(['sh', '-c', 'sleep 1 > /dev/null 2>&1 & echo $!']);
    $standIn->mustRun();
    file_put_contents(Seat::at($directory, 1)->pid(), trim($standIn->getOutput()));
    $run = new Process(
        ['perl', '-e', 'setpgrp(0,0); exec @ARGV', PHP_BINARY, Tree::at('bin/mutation-gate-pest-client'), $directory, '1', Tree::at('tests/Fixtures/Warm/run')],
        env: ['FIXTURE_END' => 'code:6'],
        timeout: Seat::WAIT,
    );
    $run->run();

    expect($run->getExitCode())->toBe(6);
})->group(...$holds);
