<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\Config\SpeedUps;
use NightWorksIO\MutationGate\Core\Local\CpuProfile;
use NightWorksIO\MutationGate\Core\Local\LocalSwitch;
use NightWorksIO\MutationGate\Core\Local\MacosClass;
use NightWorksIO\MutationGate\Core\Local\OnBattery;
use NightWorksIO\MutationGate\Core\Local\Priority;
use NightWorksIO\MutationGate\Core\Local\System;
use NightWorksIO\MutationGate\Core\Local\WindowsClass;
use NightWorksIO\MutationGate\Core\Local\WorkerShare;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('runs half the logical cores, at least one and at most eight, or every core but one', function (int $cores, int $half, int $allButOne): void {
    $counted = ProcessCount::of(max(1, $cores));

    expect(WorkerShare::Half->of($counted)->count())->toBe($half)
        ->and(WorkerShare::AllButOne->of($counted)->count())->toBe($allButOne);
})->with([
    'one core' => [1, 1, 1],
    'two cores' => [2, 1, 1],
    'eight cores' => [8, 4, 7],
    'twenty cores' => [20, 8, 19],
]);

it('takes half the cores at utility priority by default, and each class of that priority', function (): void {
    $work = LocalWork::of();

    expect($work->cpu())->toBe(CpuProfile::Balanced)
        ->and($work->workers(ProcessCount::of(8))->count())->toBe(4)
        ->and($work->priority())->toBe(Priority::Utility)
        ->and($work->macosClass())->toBe(MacosClass::Utility)
        ->and($work->linuxNice())->toBe(10)
        ->and($work->windowsClass())->toBe(WindowsClass::BelowNormal)
        ->and($work->onBattery())->toBe(OnBattery::Reduce)
        ->and($work->childWait())->toEqual(Seconds::of(1.0))
        ->and(array_map($work->isOn(...), LocalSwitch::cases()))->toBe([true, true, true, true, true]);
});

it('takes each profile\'s workers and priority unless a key sets its own, and each class unless one is set', function (): void {
    $fast = LocalWork::of(CpuProfile::Fast);
    $quiet = LocalWork::of(CpuProfile::Quiet);
    $set = LocalWork::of(CpuProfile::Quiet, 3, Priority::Normal, MacosClass::Maintenance, 15, WindowsClass::Idle);

    expect([$fast->workers(ProcessCount::of(8))->count(), $fast->priority(), $fast->macosClass(), $fast->linuxNice()])
        ->toBe([7, Priority::Normal, MacosClass::None, 0])
        ->and([$quiet->workers(ProcessCount::of(8))->count(), $quiet->priority(), $quiet->windowsClass(), $quiet->linuxNice()])
        ->toBe([1, Priority::Background, WindowsClass::Idle, 19])
        ->and([$set->workers(ProcessCount::of(8))->count(), $set->priority(), $set->macosClass(), $set->linuxNice(), $set->windowsClass()])
        ->toBe([3, Priority::Normal, MacosClass::Maintenance, 15, WindowsClass::Idle]);
});

it('lays a later layer\'s keys over an earlier\'s, key by key, a switch among them', function (): void {
    $laid = LocalWork::of(CpuProfile::Fast, speedUps: SpeedUps::of(listOnce: false))
        ->over(LocalWork::of(priority: Priority::Background, speedUps: SpeedUps::of(sizedPools: false)));

    expect([$laid->cpu(), $laid->priority(), $laid->isOn(LocalSwitch::ListOnce), $laid->isOn(LocalSwitch::SizedPools), $laid->isOn(LocalSwitch::WatchStatus)])
        ->toBe([CpuProfile::Fast, Priority::Background, false, false, true]);
});

it('names the system PHP runs on, any it does not treat apart as another', function (): void {
    expect([System::named('Darwin'), System::named('Linux'), System::named('Windows'), System::named('BSD')])
        ->toBe([System::Darwin, System::Linux, System::Windows, System::Other])
        ->and(MacosClass::None->clamps())->toBeFalse()
        ->and(MacosClass::Background->clamps())->toBeTrue();
});
