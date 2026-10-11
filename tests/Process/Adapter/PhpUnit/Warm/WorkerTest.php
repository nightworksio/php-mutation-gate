<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\End;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Worker;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Measured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\WarmWorkers;

$holds = [
    'holds:src/Adapter/PhpUnit/Warm/End.php',
    'holds:src/Adapter/PhpUnit/Warm/Waiting.php',
    'holds:src/Adapter/PhpUnit/Warm/Worker.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

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

it('forks a child for each run it claims, and writes how each ended with what it printed', function (): void {
    $workplace = WarmWorkers::place([WarmWorkers::run(['SAID' => 'one', 'CODE' => '3']), WarmWorkers::run(['SAID' => 'two', 'CODE' => '0'])]);

    $code = Worker::at($workplace->directory(), 0)->run(WarmWorkers::forking('printf "%s" "$SAID" >> "$1"; exit "$CODE"', $workplace->out(0)));
    $first = End::ranIn($workplace, 0);
    $second = End::ranIn($workplace, 1);

    expect($code)->toBe(0)
        ->and($first)->toEqual(Ran::exited(3, 'one')->took(Measured::of($first instanceof Ran ? $first : Ran::stopped(''))))
        ->and($second)->toEqual(Ran::exited(0, 'two')->took(Measured::of($second instanceof Ran ? $second : Ran::stopped(''))));
})->group(...$holds);

it('stops a child at its limit with every process under it, and says a child a signal ended was ended so', function (): void {
    $workplace = WarmWorkers::place([WarmWorkers::run(['ACT' => 'stop'], 0.3), WarmWorkers::run(['ACT' => 'kill'])]);
    $script = 'if [ "$ACT" = stop ]; then sleep 30 & echo $! > "$1.under"; sleep 30; else kill -9 $$; fi';

    Worker::at($workplace->directory(), 0)->run(WarmWorkers::forking($script, $workplace->out(0)));
    $stopped = End::ranIn($workplace, 0);
    $killed = End::ranIn($workplace, 1);

    expect($stopped instanceof Ran ? [$stopped->wasStopped(), Measured::of($stopped)->seconds() < 20.0] : [])->toBe([true, true])
        ->and($killed instanceof Ran ? [$killed->endedBySignal(), $killed->exitCode()] : [])->toBe([true, 137])
        ->and(workerIsGone((int) file_get_contents(sprintf('%s.under', $workplace->out(0)))))->toBeTrue();
})->group(...$holds);

// The child writes to its progress file as a test of it starts or ends.
it('stops a child that has made no progress for its silence limit since it last did, and holds one that never has only to its limit', function (): void {
    $directory = Scratch::directory();
    $progress = static fn(string $name): string => sprintf('%s/%s.jsonl', $directory, $name);
    $silence = static fn(string $name): SilenceLimit => SilenceLimit::of(Seconds::of(0.5), $progress($name));
    $workplace = WarmWorkers::place([
        WarmWorkers::run(['PROGRESS' => $progress('stalls'), 'ACT' => 'stall'], silence: $silence('stalls')),
        WarmWorkers::run(['PROGRESS' => $progress('silent'), 'ACT' => 'wait'], 1.0, $silence('silent')),
        WarmWorkers::run(['PROGRESS' => $progress('beats'), 'ACT' => 'beat'], silence: $silence('beats')),
    ]);
    $script = <<<'SH'
        case "$ACT" in
            stall) echo started >> "$PROGRESS"; sleep 30 ;;
            wait) sleep 30 ;;
            beat) for at in 1 2 3 4 5 6; do echo ended >> "$PROGRESS"; sleep 0.2; done ;;
        esac
        SH;

    Worker::at($workplace->directory(), 0)->run(WarmWorkers::forking($script, $workplace->out(0)));
    $ends = array_map(static fn(int $at): Ran|NotGiven => End::ranIn($workplace, $at), [0, 1, 2]);

    expect(array_map(
        static fn(Ran|NotGiven $ran): array => $ran instanceof Ran ? [$ran->wasSilenced(), $ran->wasStopped(), $ran->succeeded()] : [],
        $ends,
    ))->toBe([[true, true, false], [false, true, false], [false, false, true]])
        ->and($ends[0] instanceof Ran ? Measured::of($ends[0])->seconds() : 30.0)->toBeLessThan(20.0);
})->group(...$holds);

it('boots as PHPUnit\'s config says, its variables and its bootstrap, and forks from a boot the guard lets it', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'bootstrap.php', "<?php\n\$GLOBALS['workerBooted'] = getenv('WORKER_TOLD');\n");
    Scratch::write($root, 'phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="bootstrap.php"><php><env name="WORKER_TOLD" value="told"/></php></phpunit>');
    Scratch::write($root, 'bare.xml', '<?xml version="1.0"?><phpunit/>');
    $booted = WarmWorkers::place([WarmWorkers::run(['CODE' => '0'])], sprintf('%s/phpunit.xml', $root));

    Worker::at($booted->directory(), 0)->run(WarmWorkers::forking('exit "$CODE"', $booted->out(0)));
    $bare = WarmWorkers::place([WarmWorkers::run(['CODE' => '1'])], sprintf('%s/bare.xml', $root));
    Worker::at($bare->directory(), 0)->run(WarmWorkers::forking('exit "$CODE"', $bare->out(0)));

    expect($GLOBALS['workerBooted'] ?? null)->toBe('told')
        ->and(End::ranIn($booted, 0) instanceof Ran)->toBeTrue()
        ->and(End::ranIn($bare, 0) instanceof Ran ? End::ranIn($bare, 0)->exitCode() : null)->toBe(1);
})->group(...$holds);
