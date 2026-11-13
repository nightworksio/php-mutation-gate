<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\LocalWork;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\Local\CpuProfile;
use NightWorksIO\MutationGate\Core\Local\MacosClass;
use NightWorksIO\MutationGate\Core\Local\OnBattery;
use NightWorksIO\MutationGate\Core\Local\Priority;
use NightWorksIO\MutationGate\Core\Local\WindowsClass;
use NightWorksIO\MutationGate\Core\Local\WorkerShare;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = static fn(): array => [
    'cpu' => [LocalWork::cpu(CpuProfile::Fast), 'local.cpu', 'fast', 'cpu'],
    'workers' => [LocalWork::workers(3), 'local.workers', 3, 'workers'],
    'a share of workers' => [LocalWork::workers(WorkerShare::AllButOne), 'local.workers', 'all-but-one', 'workers'],
    'priority' => [LocalWork::priority(Priority::Normal), 'local.priority', 'normal', 'priority'],
    'macosClass' => [LocalWork::macosClass(MacosClass::Utility), 'local.macosClass', 'utility', 'macosClass'],
    'linuxNice' => [LocalWork::linuxNice(10), 'local.linuxNice', 10, 'linuxNice'],
    'windowsClass' => [LocalWork::windowsClass(WindowsClass::BelowNormal), 'local.windowsClass', 'below-normal', 'windowsClass'],
    'onBattery' => [LocalWork::onBattery(OnBattery::Pause), 'local.onBattery', 'pause', 'onBattery'],
    'childWait' => [LocalWork::childWait('90s'), 'local.childWait', '90s', 'childWait'],
    'watchStatus' => [LocalWork::watchStatus(on: false), 'local.watchStatus', false, 'watchStatus'],
    'coverageAlone' => [LocalWork::coverageAlone(on: false), 'local.coverageAlone', false, 'coverageAlone'],
    'sizedPools' => [LocalWork::sizedPools(on: false), 'local.sizedPools', false, 'sizedPools'],
    'listOnce' => [LocalWork::listOnce(on: false), 'local.listOnce', false, 'listOnce'],
    'streamBatches' => [LocalWork::streamBatches(on: false), 'local.streamBatches', false, 'streamBatches'],
    'recordLines' => [LocalWork::recordLines(on: false), 'local.recordLines', false, 'recordLines'],
    'siteHits' => [LocalWork::siteHits(on: false), 'local.siteHits', false, 'siteHits'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Setting $call, string $path, string|int|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('LocalWork::%s(', $method));
})->with($calls);
