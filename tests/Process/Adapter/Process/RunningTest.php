<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\MutantFile;
use NightWorksIO\MutationGate\Adapter\Process\Running;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\FileWrapper;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Started;

$holds = [
    'holds:src/Adapter/Process/Running.php',
];

beforeEach(function (): void {
    FileWrapper::keep();
});

afterEach(function (): void {
    FileWrapper::restore();
    Scratch::sweep();
});

$started = static fn(string $code): Running => Started::command(
    ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', $code)->within(Seconds::of(50.0)),
);

$ended = static function (Running $running): Ran {
    do {
        Running::awaitAny(Seconds::of(1.0), $running);
        $end = $running->endedBy(0.0);
    } while ($end instanceof NotGiven);

    return $end;
};

$waited = static function (Running $running, float $atMost): float {
    $from = hrtime(as_number: true);
    Running::awaitAny(Seconds::of($atMost), $running);

    return (hrtime(as_number: true) - $from) / Seconds::NANOSECONDS;
};

it('wakes a wait as soon as a running program prints, long before the wait is up', function () use ($started, $waited): void {
    $running = $started('echo "ready"; sleep(30);');

    expect($waited($running, 20.0))->toBeLessThan(5.0)
        ->and($running->endedBy(0.0))->toBeInstanceOf(NotGiven::class)
        ->and($running->endedBy(100.0))->toEqual(Ran::stopped('ready', 'ready')->took(Seconds::of(100.0)));
})->group(...$holds);

it('wakes a wait as soon as a running program ends', function () use ($started, $waited, $ended): void {
    $running = $started('usleep(100000);');

    expect($waited($running, 20.0))->toBeLessThan(5.0)
        ->and($ended($running)->succeeded())->toBeTrue();
})->group(...$holds);

it('waits a moment, not the whole wait, on a program that has closed its pipes and runs on', function () use ($started, $waited): void {
    $running = $started('fclose(STDOUT); fclose(STDERR); sleep(30);');

    // Each look wakes at once on a pipe's end, and the look after it closes that pipe.
    for ($looks = 0; $looks < 3; $looks++) {
        $waited($running, 20.0);
        $running->endedBy(0.0);
    }

    expect($waited($running, 20.0))->toBeLessThan(1.0)
        ->and($running->endedBy(100.0))->toEqual(Ran::stopped('', '')->took(Seconds::of(100.0)));
})->group(...$holds);

it('keeps all a program prints however much more it is than a pipe holds', function () use ($started, $ended): void {
    $ran = $ended($started('echo str_repeat("x", 1048576); fwrite(STDERR, str_repeat("y", 1048576));'));

    expect(strlen($ran->printed()))->toBe(1_048_576)
        ->and(strlen($ran->output()))->toBe(2_097_152);
})->group(...$holds);

it('says a program a signal ended was signalled, keeping what it printed', function () use ($ended): void {
    $ran = $ended(Started::command(ProcessCommand::of(Scratch::directory(), 'sh', '-c', 'echo x; kill -9 $$')));

    expect($ran)->toEqual(Ran::signalled(9, "x\n", "x\n")->took(Seconds::of(0.0)))
        ->and($ran->endedBySignal())->toBeTrue();
})->group(...$holds);

it('unsets a variable this process holds where its command unsets it', function () use ($ended): void {
    putenv('MUTATION_GATE_RUNNING=held');

    try {
        $command = ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', 'echo getenv("MUTATION_GATE_RUNNING") === false ? "unset" : "held";');
        $unsetting = $command->with(Environment::unsetting('MUTATION_GATE_RUNNING'));

        expect($ended(Started::command($command))->output())->toBe('held')
            ->and($ended(Started::command($unsetting))->output())->toBe('unset');
    } finally {
        putenv('MUTATION_GATE_RUNNING');
    }
})->group(...$holds);

it('hands a program what $_ENV holds over this process\'s own environment, as Symfony\'s Process does', function () use ($ended): void {
    putenv('MUTATION_GATE_RUNNING=own');
    $_ENV['MUTATION_GATE_RUNNING'] = 'told';
    $_ENV['MUTATION_GATE_RUNNING_ALONE'] = 'alone';

    try {
        $command = ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', 'echo getenv("MUTATION_GATE_RUNNING"), " ", getenv("MUTATION_GATE_RUNNING_ALONE");');

        expect($ended(Started::command($command))->output())->toBe('told alone');
    } finally {
        putenv('MUTATION_GATE_RUNNING');
        unset($_ENV['MUTATION_GATE_RUNNING'], $_ENV['MUTATION_GATE_RUNNING_ALONE']);
    }
})->group(...$holds);

it('gives a program an input that has ended as it starts', function () use ($started, $ended): void {
    $ran = $ended($started('$in = [STDIN]; $none = null; echo stream_select($in, $none, $none, 3) === 1 ? strlen((string) stream_get_contents(STDIN)) : "open";'));

    expect([$ran->succeeded(), $ran->output()])->toBe([true, '0']);
})->group(...$holds);

it('starts a program while a stream wrapper stands in for files, as a mutant\'s run puts one', function () use ($started, $ended): void {
    $directory = (string) realpath(Scratch::directory());
    Scratch::write($directory, 'Served.php', '<?php');
    Scratch::write($directory, 'guard.txt', '');
    MutantFile::serve(sprintf('%s/Served.php', $directory), sprintf('%s/Served.php', $directory), sprintf('%s/guard.txt', $directory), Guard::SERVED);

    expect($ended($started('echo "ran";'))->output())->toBe('ran');
})->group(...$holds);

it('stops a program at the moment its deadline comes', function () use ($started): void {
    $running = $started('sleep(30);');

    expect($running->endedBy(50.0))->toEqual(Ran::stopped('', '')->took(Seconds::of(50.0)));
})->group(...$holds);

it('wakes a wait as soon as any of the running programs prints', function () use ($started): void {
    $printing = $started('usleep(200000); echo "ready"; sleep(30);');
    $silent = $started('sleep(30);');
    $from = hrtime(as_number: true);

    Running::awaitAny(Seconds::of(20.0), $printing, $silent);

    expect((hrtime(as_number: true) - $from) / Seconds::NANOSECONDS)->toBeLessThan(5.0)
        ->and($printing->endedBy(100.0))->toBeInstanceOf(Ran::class)
        ->and($silent->endedBy(100.0))->toBeInstanceOf(Ran::class);
})->group(...$holds);

it('waits the whole wait where nothing runs', function (): void {
    $from = hrtime(as_number: true);

    Running::awaitAny(Seconds::of(0.2));

    expect((hrtime(as_number: true) - $from) / Seconds::NANOSECONDS)->toBeGreaterThanOrEqual(0.2);
})->group(...$holds);

it('reaps each program it ends and closes its pipes, whether it exited or was stopped', function () use ($started, $ended): void {
    $processes = count(get_resources('process'));
    $streams = count(get_resources('stream'));
    $exited = $started('echo "done";');
    $stopped = $started('sleep(30);');

    $ran = [$ended($exited)->output(), $stopped->endedBy(100.0)];

    expect([count(get_resources('process')), count(get_resources('stream'))])->toBe([$processes, $streams])
        ->and($ran[0])->toBe('done');
})->group(...$holds);

it('reads a program\'s progress afresh at each look, though it was read before the program grew it and no pipe was read between', function (): void {
    $progress = sprintf('%s/progress', Scratch::directory());
    file_put_contents($progress, 'a');
    $grows = sprintf(
        'fclose(STDOUT); fclose(STDERR); usleep(500000); file_put_contents(%s, "b", FILE_APPEND); sleep(30);',
        var_export($progress, return: true),
    );
    $running = Started::command(
        ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', $grows)
            ->within(Seconds::of(50.0))
            ->silencedAfter(SilenceLimit::of(Seconds::of(5.0), $progress)),
    );
    $read = [$running->endedBy(0.5)];
    Running::awaitAny(Seconds::of(0.2));
    $read = [...$read, $running->endedBy(0.6), $running->endedBy(1.0)];
    Running::awaitAny(Seconds::of(1.5));

    expect([...$read, $running->endedBy(4.0), $running->endedBy(8.0)])->toEqual(array_fill(0, 5, NotGiven::value()))
        ->and($running->endedBy(9.5))->toEqual(Ran::silenced('', '')->took(Seconds::of(9.5)));
})->group(...$holds);

it('keeps what a program printed before it was seen to end', function () use ($started): void {
    $running = $started('echo "done";');
    Running::awaitAny(Seconds::of(0.5));

    expect($running->endedBy(0.0))->toEqual(Ran::exited(0, 'done', 'done')->took(Seconds::of(0.0)));
})->group(...$holds);

it('ends at once a program its tree\'s stop missed, whatever other signal it catches', function () use ($started): void {
    $running = $started('pcntl_async_signals(true); pcntl_signal(8, static function (): void {}); pcntl_signal(10, static function (): void {}); echo "up"; $end = microtime(true) + 5; while (microtime(true) < $end) { usleep(10000); }');
    Running::awaitAny(Seconds::of(0.5));
    $path = getenv('PATH');
    putenv('PATH=/nowhere');
    $from = hrtime(as_number: true);

    try {
        $ran = $running->endedBy(100.0);
    } finally {
        putenv(sprintf('PATH=%s', $path));
    }

    expect((hrtime(as_number: true) - $from) / Seconds::NANOSECONDS)->toBeLessThan(2.0)
        ->and($ran)->toEqual(Ran::stopped('up', 'up')->took(Seconds::of(100.0)));
})->skip(! function_exists('pcntl_signal'), 'catching a signal takes pcntl')->group(...$holds);

it('waits on as a signal this process catches cuts a wait short, saying nothing of it', function () use ($started): void {
    $running = $started('sleep(30);');
    pcntl_signal(SIGALRM, static function (): void {
    });
    pcntl_alarm(1);
    error_clear_last();

    try {
        Running::awaitAny(Seconds::of(3.0), $running);
    } finally {
        pcntl_signal(SIGALRM, SIG_DFL);
    }

    expect(error_get_last())->toBeNull()
        ->and($running->endedBy(100.0))->toBeInstanceOf(Ran::class);
})->skip(! function_exists('pcntl_alarm'), 'raising a signal takes pcntl')->group(...$holds);
