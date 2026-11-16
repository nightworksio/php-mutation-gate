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

$calls = [
    'cpu' => [static fn(): Setting => LocalWork::cpu(CpuProfile::Fast), 'local.cpu', 'fast', 'cpu'],
    'workers' => [static fn(): Setting => LocalWork::workers(3), 'local.workers', 3, 'workers'],
    'a share of workers' => [static fn(): Setting => LocalWork::workers(WorkerShare::AllButOne), 'local.workers', 'all-but-one', 'workers'],
    'priority' => [static fn(): Setting => LocalWork::priority(Priority::Normal), 'local.priority', 'normal', 'priority'],
    'macosClass' => [static fn(): Setting => LocalWork::macosClass(MacosClass::Utility), 'local.macosClass', 'utility', 'macosClass'],
    'linuxNice' => [static fn(): Setting => LocalWork::linuxNice(10), 'local.linuxNice', 10, 'linuxNice'],
    'windowsClass' => [static fn(): Setting => LocalWork::windowsClass(WindowsClass::BelowNormal), 'local.windowsClass', 'below-normal', 'windowsClass'],
    'onBattery' => [static fn(): Setting => LocalWork::onBattery(OnBattery::Pause), 'local.onBattery', 'pause', 'onBattery'],
    'childWait' => [static fn(): Setting => LocalWork::childWait('90s'), 'local.childWait', '90s', 'childWait'],
    'watchStatus' => [static fn(): Setting => LocalWork::watchStatus(on: false), 'local.watchStatus', false, 'watchStatus'],
    'coverageAlone' => [static fn(): Setting => LocalWork::coverageAlone(on: false), 'local.coverageAlone', false, 'coverageAlone'],
    'sizedPools' => [static fn(): Setting => LocalWork::sizedPools(on: false), 'local.sizedPools', false, 'sizedPools'],
    'listOnce' => [static fn(): Setting => LocalWork::listOnce(on: false), 'local.listOnce', false, 'listOnce'],
    'streamBatches' => [static fn(): Setting => LocalWork::streamBatches(on: false), 'local.streamBatches', false, 'streamBatches'],
    'recordLines' => [static fn(): Setting => LocalWork::recordLines(on: false), 'local.recordLines', false, 'recordLines'],
    'siteHits' => [static fn(): Setting => LocalWork::siteHits(on: false), 'local.siteHits', false, 'siteHits'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|int|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('LocalWork::%s(', $method));
})->with($calls);
