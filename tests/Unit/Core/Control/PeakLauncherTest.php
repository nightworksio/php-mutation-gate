<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the peak the script wrote in kilobytes, or in bytes on macOS, and none from what is no whole number above nought', function (string $written, string $family, MemoryCap|NotGiven $peak): void {
    expect(PeakLauncher::peakIn($written, $family))->toEqual($peak);
})->with([
    'kilobytes on Linux' => ["2048\n", 'Linux', fn(): MemoryCap => MemoryCap::of(2, MemoryUnit::Megabytes)],
    'bytes on macOS' => ['2097152', 'Darwin', fn(): MemoryCap => MemoryCap::of(2, MemoryUnit::Megabytes)],
    'nothing' => ['', 'Linux', fn(): NotGiven => NotGiven::value()],
    'nought' => ['0', 'Linux', fn(): NotGiven => NotGiven::value()],
    'not a number' => ['12k', 'Linux', fn(): NotGiven => NotGiven::value()],
    'a sign' => ['+12', 'Linux', fn(): NotGiven => NotGiven::value()],
]);

it('gives a control the peak the script wrote, and leaves one without it where it wrote none', function (): void {
    $run = ControlRun::passed(Seconds::of(1.0));

    expect(PeakLauncher::measured($run, '4096', 'Linux')->peak())->toEqual(MemoryCap::of(4, MemoryUnit::Megabytes))
        ->and(PeakLauncher::measured($run, '', 'Linux'))->toBe($run);
});

it('writes its script in a directory of a runner\'s controls', function (): void {
    expect(PeakLauncher::in('/work/unmutated'))->toBe('/work/unmutated/launcher.php');
});
