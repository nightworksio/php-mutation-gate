<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\Running;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Started;

afterEach(function (): void {
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
});

it('wakes a wait as soon as a running program ends', function () use ($started, $waited, $ended): void {
    $running = $started('usleep(100000);');

    expect($waited($running, 20.0))->toBeLessThan(5.0)
        ->and($ended($running)->succeeded())->toBeTrue();
});

it('waits a moment, not the whole wait, on a program that has closed its pipes and runs on', function () use ($started, $waited): void {
    $running = $started('fclose(STDOUT); fclose(STDERR); sleep(30);');

    // Each look wakes at once on a pipe's end, and the look after it closes that pipe.
    for ($looks = 0; $looks < 3; $looks++) {
        $waited($running, 20.0);
        $running->endedBy(0.0);
    }

    expect($waited($running, 20.0))->toBeLessThan(1.0)
        ->and($running->endedBy(100.0))->toEqual(Ran::stopped('', '')->took(Seconds::of(100.0)));
});

it('keeps all a program prints however much more it is than a pipe holds', function () use ($started, $ended): void {
    $ran = $ended($started('echo str_repeat("x", 1048576); fwrite(STDERR, str_repeat("y", 1048576));'));

    expect(strlen($ran->printed()))->toBe(1_048_576)
        ->and(strlen($ran->output()))->toBe(2_097_152);
});

it('says a program a signal ended was signalled, keeping what it printed', function () use ($ended): void {
    $ran = $ended(Started::command(ProcessCommand::of(Scratch::directory(), 'sh', '-c', 'echo x; kill -9 $$')));

    expect($ran)->toEqual(Ran::signalled(9, "x\n", "x\n")->took(Seconds::of(0.0)))
        ->and($ran->endedBySignal())->toBeTrue();
});

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
});

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
});
