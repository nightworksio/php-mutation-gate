<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the peak the script wrote in kilobytes, or in bytes on macOS, and none from what is no whole number above nought', function (string $written, string $family, MemoryCap|NotGiven $peak): void {
    expect(PeakLauncher::peakIn($written, $family))->toEqual($peak);
})->with([
    'kilobytes on Linux' => ["2048\n", 'Linux', MemoryCap::of(2, MemoryUnit::Megabytes)],
    'bytes on macOS' => ['2097152', 'Darwin', MemoryCap::of(2, MemoryUnit::Megabytes)],
    'nothing' => ['', 'Linux', NotGiven::value()],
    'nought' => ['0', 'Linux', NotGiven::value()],
    'not a number' => ['12k', 'Linux', NotGiven::value()],
    'a sign' => ['+12', 'Linux', NotGiven::value()],
]);

it('gives a control the peak the script wrote, and leaves one without it where it wrote none', function (): void {
    $run = ControlRun::passed(Seconds::of(1.0));

    expect(PeakLauncher::measured($run, '4096', 'Linux')->peak())->toEqual(MemoryCap::of(4, MemoryUnit::Megabytes))
        ->and(PeakLauncher::measured($run, '', 'Linux'))->toBe($run);
});

it('writes its script in a directory of a runner\'s controls', function (): void {
    expect(PeakLauncher::in('/work/unmutated'))->toBe('/work/unmutated/launcher.php');
});

it('runs a command with its own output and exit code, and writes the most memory it held', function (): void {
    $directory = Scratch::directory();
    $launcher = PeakLauncher::in($directory);
    $peak = sprintf('%s/peak', $directory);
    file_put_contents($launcher, PeakLauncher::SCRIPT);
    $process = new Process([PHP_BINARY, $launcher, $peak, PHP_BINARY, '-r', '$held = str_repeat("x", 64 * 1024 * 1024); echo "held"; exit(3);']);
    $process->run();
    $measured = PeakLauncher::peakIn((string) file_get_contents($peak), PHP_OS_FAMILY);

    expect($process->getOutput())->toBe('held')
        ->and($process->getExitCode())->toBe(3)
        ->and($measured instanceof MemoryCap ? $measured->bytes() : 0)->toBeGreaterThan(64 * 1024 * 1024);
});
