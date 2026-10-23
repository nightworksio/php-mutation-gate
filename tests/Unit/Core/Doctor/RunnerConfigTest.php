<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\PhpUnitMemory;
use NightWorksIO\MutationGate\Core\Doctor\RunnerConfig;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;

it('says nothing where nobody read the runner\'s config', function (): void {
    expect(RunnerConfig::none()->phpUnitMemory())->toEqual(NotGiven::value())
        ->and(RunnerConfig::none()->suitesElsewhere())->toBe([]);
});

it('keeps the PHPUnit config\'s memory_limit and the suites served elsewhere, whatever order they come in', function (): void {
    $memory = PhpUnitMemory::of(Path::of('phpunit.xml'), MemoryCap::of(2, MemoryUnit::Gigabytes));
    $first = RunnerConfig::none()->withPhpUnitMemory($memory)->withSuitesElsewhere(['Acceptance', 'Api']);
    $second = RunnerConfig::none()->withSuitesElsewhere(['Acceptance', 'Api'])->withPhpUnitMemory($memory);

    expect([$first->phpUnitMemory(), $first->suitesElsewhere()])->toBe([$memory, ['Acceptance', 'Api']])
        ->and([$second->phpUnitMemory(), $second->suitesElsewhere()])->toBe([$memory, ['Acceptance', 'Api']]);
});
