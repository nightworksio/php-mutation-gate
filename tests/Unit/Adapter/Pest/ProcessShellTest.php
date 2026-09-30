<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Clock;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Pest\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('runs a program in its directory, with its environment, and keeps both of its outputs', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $command = Command::of(PHP_BINARY, '-r', 'echo getcwd(), " ", getenv("GATE"); fwrite(STDERR, "!");')
        ->with(['GATE' => 'on']);
    $ran = new ProcessShell($directory)->run($command);

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: sprintf('%s on!', $directory))->taking($ran->took()));
});

it('runs a program in another directory once moved there', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $ran = new ProcessShell('/')->in($directory)->run(Command::of(PHP_BINARY, '-r', 'echo getcwd();'));

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: $directory)->taking($ran->took()));
});

it('says a program that exits with a failure did not succeed', function (): void {
    $ran = new ProcessShell(Scratch::directory())->run(Command::of(PHP_BINARY, '-r', 'echo "no"; exit(3);'));

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'no')->taking($ran->took()));
});

it('stops a program at its deadline, keeping what it printed', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $command = Command::of(PHP_BINARY, '-r', 'echo "started"; flush(); touch("printed"); sleep(20);')
        ->within(Seconds::of(1.0));
    // The clock stands still until the program has printed, then passes every deadline, however slow starting was.
    $clock = new readonly class ($directory) implements Clock {
        public function __construct(private string $directory)
        {
        }

        public function seconds(): float
        {
            return is_file(sprintf('%s/printed', $this->directory)) ? INF : 0.0;
        }
    };

    $ran = new ProcessShell($directory, $clock)->run($command);

    expect($ran)->toEqual(Ran::stopped('started')->taking($ran->took()));
});

it('measures a deadline on the system\'s clock', function (): void {
    $command = Command::of(PHP_BINARY, '-r', 'sleep(20);')->within(Seconds::of(0.0));

    $ran = new ProcessShell(Scratch::directory())->run($command);

    expect($ran)->toEqual(Ran::stopped('')->taking($ran->took()));
});

it('measures how long a program ran on its clock, from its start to its end', function (): void {
    // The clock reads 10 when the program starts, and 12.5 at every look after.
    $clock = new class implements Clock {
        private bool $started = false;

        public function seconds(): float
        {
            $seconds = $this->started ? 12.5 : 10.0;
            $this->started = true;

            return $seconds;
        }
    };
    $ran = new ProcessShell(Scratch::directory(), $clock)->run(Command::of(PHP_BINARY, '-r', 'echo "ok";'));

    expect($ran->took())->toEqual(Seconds::of(2.5))
        ->and($ran->output())->toBe('ok');
});

it('measures how long a program ran until it was stopped', function (): void {
    $clock = new class implements Clock {
        private float $seconds = 0.0;

        public function seconds(): float
        {
            $this->seconds += 1.0;

            return $this->seconds;
        }
    };
    $ran = new ProcessShell(Scratch::directory(), $clock)->run(Command::of(PHP_BINARY, '-r', 'sleep(20);')->within(Seconds::of(2.0)));

    // Started at 1; the deadline, at 3, has passed at the look that reads 3; the time taken is read at 4.
    expect($ran->wasStopped())->toBeTrue()
        ->and($ran->took())->toEqual(Seconds::of(3.0));
});

it('says a program that cannot be started did not succeed, and why', function (): void {
    $ran = new ProcessShell('/nowhere/at/all')->run(Command::of(PHP_BINARY, '-v'));

    expect($ran->succeeded())->toBeFalse()
        ->and($ran->output())->toContain('/nowhere/at/all');
});
