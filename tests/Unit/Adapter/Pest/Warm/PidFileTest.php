<?php

declare(strict_types=1);

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
