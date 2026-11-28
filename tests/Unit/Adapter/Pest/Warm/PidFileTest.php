<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Living;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\PidFile;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Passing;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the process id a file holds, and none where it holds no whole number or is not there', function (string $held, Closure $read): void {
    $file = sprintf('%s/1.pid', Scratch::directory());

    if ($held !== '') {
        file_put_contents($file, $held);
    }

    expect(PidFile::read($file))->toEqual($read());
})->with([
    'a process id' => ['4242', fn(): int => 4242],
    'no file' => ['', fn(): NotGiven => NotGiven::value()],
    'text' => ['"worker"', fn(): NotGiven => NotGiven::value()],
    'no JSON' => ['42 43', fn(): NotGiven => NotGiven::value()],
]);

it('tells whether the process a file names is still there', function (): void {
    $file = sprintf('%s/1.pid', Scratch::directory());
    file_put_contents($file, (string) getmypid());
    $running = PidFile::isRunning($file);
    file_put_contents($file, (string) Passing::NONE);

    expect($running)->toBeTrue()
        ->and(PidFile::isRunning($file))->toBeFalse()
        ->and(PidFile::isRunning(sprintf('%s/2.pid', Scratch::directory())))->toBeFalse();
});

it('takes a process that ended and was not collected as no longer running, where Linux tells its state', function (): void {
    $file = sprintf('%s/1.pid', Scratch::directory());
    $started = proc_open(['sleep', '0.2'], [], $pipes);
    file_put_contents($file, (string) (is_resource($started) ? proc_get_status($started)['pid'] : Passing::NONE));
    $began = PidFile::isRunning($file);
    $until = hrtime(as_number: true) + 5_000_000_000;
    $running = $began;

    while ($running && hrtime(as_number: true) < $until) {
        $running = PidFile::isRunning($file);
    }

    if (is_resource($started)) {
        proc_close($started);
    }

    expect($began)->toBeTrue()->and($running)->toBeFalse();
})->skip(fn(): bool => ! is_readable(sprintf(Living::STAT, (int) getmypid())), 'this system tells no process state in /proc');
