<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\End;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Forking;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Job;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Refusal;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\WarmRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Worker;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workplace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Measured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Forking that makes each child a shell running this script, told what the
 * run tells it, with the worker's standard output file as its argument: it
 * stands in for PHPUnit, which only a worker's own script runs. The child
 * replaces itself at once, so nothing of the test's process runs in it.
 */
function workerForking(string $script, string $out): Forking
{
    return new readonly class ($script, $out) implements Forking {
        public function __construct(private string $script, private string $out)
        {
        }

        public function forked(WarmRun $run): int
        {
            $child = pcntl_fork();

            if ($child === 0) {
                pcntl_exec('/bin/sh', ['-c', $this->script, 'sh', $this->out], $run->environment());
                exit(127);
            }

            return $child;
        }
    };
}

/**
 * A workplace holding a job of these runs, with this config, which no run starts in after the end, of a run that
 * mutates these files.
 *
 * @param list<WarmRun> $runs
 */
function workerPlace(
    array $runs,
    string|NotGiven $config = new NotGiven(),
    float|NotGiven $end = new NotGiven(),
    string ...$mutated,
): Workplace {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $workplace->opened(Job::of(Tree::at('vendor/autoload.php'), $config, array_values($mutated), $end, $runs));

    return $workplace;
}

/**
 * Whether a process has ended: no longer listed, or listed only as a zombie
 * its new parent has yet to reap. It asks the process table again for up to
 * two hundred times, which gives the system time to reap it without the test
 * sleeping.
 */
function workerIsGone(int $process): bool
{
    for ($asked = 0; $asked < 200; $asked++) {
        $listed = [];
        exec(sprintf('ps -o stat= -p %d', $process), $listed);
        $state = trim(implode('', $listed));

        if ($state === '' || str_starts_with($state, 'Z')) {
            return true;
        }
    }

    return false;
}

/** @param array<string, string> $told */
function workerRun(array $told, float $limit = 30.0, SilenceLimit|NotGiven $silence = new NotGiven()): WarmRun
{
    return WarmRun::of([], $told, $limit, '', '', '', $silence);
}

it('forks a child for each run it claims, and writes how each ended with what it printed', function (): void {
    $workplace = workerPlace([workerRun(['SAID' => 'one', 'CODE' => '3']), workerRun(['SAID' => 'two', 'CODE' => '0'])]);

    $code = Worker::at($workplace->directory(), 0)->run(workerForking('printf "%s" "$SAID" >> "$1"; exit "$CODE"', $workplace->out(0)));
    $first = End::ranIn($workplace, 0);
    $second = End::ranIn($workplace, 1);

    expect($code)->toBe(0)
        ->and($first)->toEqual(Ran::exited(3, 'one')->took(Measured::of($first instanceof Ran ? $first : Ran::stopped(''))))
        ->and($second)->toEqual(Ran::exited(0, 'two')->took(Measured::of($second instanceof Ran ? $second : Ran::stopped(''))));
});

it('stops a child at its limit with every process under it, and says a child a signal ended was ended so', function (): void {
    $workplace = workerPlace([workerRun(['ACT' => 'stop'], 0.3), workerRun(['ACT' => 'kill'])]);
    $script = 'if [ "$ACT" = stop ]; then sleep 30 & echo $! > "$1.under"; sleep 30; else kill -9 $$; fi';

    Worker::at($workplace->directory(), 0)->run(workerForking($script, $workplace->out(0)));
    $stopped = End::ranIn($workplace, 0);
    $killed = End::ranIn($workplace, 1);

    expect($stopped instanceof Ran ? [$stopped->wasStopped(), Measured::of($stopped)->seconds() < 20.0] : [])->toBe([true, true])
        ->and($killed instanceof Ran ? [$killed->endedBySignal(), $killed->exitCode()] : [])->toBe([true, 137])
        ->and(workerIsGone((int) file_get_contents(sprintf('%s.under', $workplace->out(0)))))->toBeTrue();
});

// The child writes to its progress file as a test of it starts or ends.
it('stops a child that has made no progress for its silence limit since it last did, and holds one that never has only to its limit', function (): void {
    $directory = Scratch::directory();
    $progress = static fn(string $name): string => sprintf('%s/%s.jsonl', $directory, $name);
    $silence = static fn(string $name): SilenceLimit => SilenceLimit::of(Seconds::of(0.5), $progress($name));
    $workplace = workerPlace([
        workerRun(['PROGRESS' => $progress('stalls'), 'ACT' => 'stall'], silence: $silence('stalls')),
        workerRun(['PROGRESS' => $progress('silent'), 'ACT' => 'wait'], 1.0, $silence('silent')),
        workerRun(['PROGRESS' => $progress('beats'), 'ACT' => 'beat'], silence: $silence('beats')),
    ]);
    $script = <<<'SH'
        case "$ACT" in
            stall) echo started >> "$PROGRESS"; sleep 30 ;;
            wait) sleep 30 ;;
            beat) for at in 1 2 3 4 5 6; do echo ended >> "$PROGRESS"; sleep 0.2; done ;;
        esac
        SH;

    Worker::at($workplace->directory(), 0)->run(workerForking($script, $workplace->out(0)));
    $ends = array_map(static fn(int $at): Ran|NotGiven => End::ranIn($workplace, $at), [0, 1, 2]);

    expect(array_map(
        static fn(Ran|NotGiven $ran): array => $ran instanceof Ran ? [$ran->wasSilenced(), $ran->wasStopped(), $ran->succeeded()] : [],
        $ends,
    ))->toBe([[true, true, false], [false, true, false], [false, false, true]])
        ->and($ends[0] instanceof Ran ? Measured::of($ends[0])->seconds() : 30.0)->toBeLessThan(20.0);
});

it('starts no run once the job\'s end has come', function (): void {
    $workplace = workerPlace([workerRun(['SAID' => 'late', 'CODE' => '0'])], end: microtime(as_float: true) - 1.0);

    Worker::at($workplace->directory(), 0)->run(workerForking('exit 0', $workplace->out(0)));

    expect(End::ranIn($workplace, 0))->toEqual(NotGiven::value());
});

it('boots as PHPUnit\'s config says, its variables and its bootstrap, and forks from a boot the guard lets it', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'bootstrap.php', "<?php\n\$GLOBALS['workerBooted'] = getenv('WORKER_TOLD');\n");
    Scratch::write($root, 'phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="bootstrap.php"><php><env name="WORKER_TOLD" value="told"/></php></phpunit>');
    Scratch::write($root, 'bare.xml', '<?xml version="1.0"?><phpunit/>');
    $booted = workerPlace([workerRun(['CODE' => '0'])], sprintf('%s/phpunit.xml', $root));

    Worker::at($booted->directory(), 0)->run(workerForking('exit "$CODE"', $booted->out(0)));
    $bare = workerPlace([workerRun(['CODE' => '1'])], sprintf('%s/bare.xml', $root));
    Worker::at($bare->directory(), 0)->run(workerForking('exit "$CODE"', $bare->out(0)));

    expect($GLOBALS['workerBooted'] ?? null)->toBe('told')
        ->and(End::ranIn($booted, 0) instanceof Ran)->toBeTrue()
        ->and(End::ranIn($bare, 0) instanceof Ran ? End::ranIn($bare, 0)->exitCode() : null)->toBe(1);
});

it('forks nothing, and says why, where its job cannot be read', function (): void {
    $missing = Workplace::at(Scratch::directory());
    $garbled = workerPlace([]);
    file_put_contents($garbled->job(), '{"autoloader": 3}');

    $code = Worker::at($missing->directory(), 2)->run(workerForking('exit 0', $missing->out(2)));
    Worker::at($garbled->directory(), 0)->run(workerForking('exit 0', $garbled->out(0)));
    $read = Refusal::readFrom($garbled->refused(0));

    expect($code)->toBe(0)
        ->and(Refusal::readFrom($missing->refused(2)))->toEqual(Refusal::guarded(sprintf('The worker could not read its job: %s', $missing->job())))
        ->and($read instanceof Refusal ? $read->reason() : '')->toStartWith('The worker could not read its job: ')
        ->and(Worker::at($missing->directory(), 2)->refused(Refusal::unforkable('no pcntl')))->toBe(0);
});

it('runs the worker script in this package\'s own directory', function (): void {
    expect(Worker::script())->toBe(Tree::at('bin/mutation-gate-worker'))
        ->and(is_file(Worker::script()))->toBeTrue();
});

it('forks nothing, and says why, from a boot the guard refuses', function (): void {
    $workplace = workerPlace([workerRun(['CODE' => '0'])], new NotGiven(), new NotGiven(), Tree::at('src/Core/NotGiven.php'));

    $code = Worker::at($workplace->directory(), 0)->run(workerForking('exit "$CODE"', $workplace->out(0)));

    expect($code)->toBe(0)
        ->and(End::ranIn($workplace, 0))->toEqual(NotGiven::value())
        ->and(Refusal::readFrom($workplace->refused(0)))->toBeInstanceOf(Refusal::class);
});
