<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Doctor\Asked;
use NightWorksIO\MutationGate\Core\Doctor\Check\Memory;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Measurement;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\PhpUnitMemory;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$capped = static fn(string $memory): Observations => Observations::none()
    ->withSettings(Configs::settings(['runner' => ['use' => 'pest', 'memory' => $memory]]));

$lifting = static fn(Observations $observed, MemoryCap $limit): Observations => $observed
    ->withFiles(ProjectFiles::none()->withPhpUnitMemory(PhpUnitMemory::of(Path::of('phpunit.xml.dist'), $limit)));

$peaking = static fn(Observations $observed, MemoryCap $peak): Observations => $observed->withAsked(
    Asked::nothing()->withMeasurement(Measurement::of(CoverageMap::empty(), Units::none(), Timings::none())->peakingAt($peak)),
);

it('advises a cap where runner.memory sets none', function () use ($capped): void {
    expect(Memory::in($capped('-1')))->toEqual(Findings::of(Finding::of(
        Slug::MemoryUncapped,
        Severity::Advice,
        'runner.memory is -1, so no mutant\'s process has a memory cap.',
        'A mutant that runs away with memory takes the machine down, with every mutant still to run on it.',
        'Set runner.memory above what the suite needs, such as 1G: doctor --measure says what it needs.',
    )));
});

it('finds a PHPUnit config that lifts the cap, to more or to none', function (MemoryCap $limit, string $written) use (
    $capped,
    $lifting,
): void {
    expect(Memory::in($lifting($capped('512M'), $limit)))->toEqual(Findings::of(Finding::of(
        Slug::MemoryCapLifted,
        Severity::Advice,
        sprintf('phpunit.xml.dist sets memory_limit to %s, over the 512M cap runner.memory sets.', $written),
        'PHPUnit sets it as it starts, after PHP reads the cap, so each mutant runs under it in place of the cap.',
        'Take <ini name="memory_limit"> out of phpunit.xml.dist, or set it no higher than 512M; raise runner.memory to give more.',
    )));
})->with([
    'more' => [fn(): MemoryCap => MemoryCap::of(1, MemoryUnit::Gigabytes), '1G'],
    'none' => [fn(): MemoryCap => MemoryCap::none(), '-1'],
]);

it('finds nothing where the PHPUnit config keeps to the cap, or sets no limit of its own', function () use (
    $capped,
    $lifting,
): void {
    expect(Memory::in($lifting($capped('512M'), MemoryCap::of(512, MemoryUnit::Megabytes))))->toEqual(Findings::none())
        ->and(Memory::in($lifting($capped('512M'), MemoryCap::of(256, MemoryUnit::Megabytes))))->toEqual(Findings::none())
        ->and(Memory::in($capped('512M')))->toEqual(Findings::none());
});

it('finds a suite that already needs over half the cap, under --measure', function () use ($capped, $peaking): void {
    expect(Memory::in($peaking($capped('1G'), MemoryCap::of(600, MemoryUnit::Megabytes))))->toEqual(Findings::of(Finding::of(
        Slug::MemoryCapNear,
        Severity::Advice,
        'The suite\'s processes peaked at 600M resident, over half the 1G cap runner.memory sets.',
        'Each mutant runs under the cap, so a mutant that needs more than it is stopped by the cap rather than by a test,'
        . "\nand a plan refuses a suite whose coverage run held more than the cap.",
        'Set runner.memory to at least 1200M.',
    )))
        ->and(Memory::in($peaking($capped('1G'), MemoryCap::of(512, MemoryUnit::Megabytes))))->toEqual(Findings::none())
        ->and(Memory::in($peaking($capped('-1'), MemoryCap::of(4, MemoryUnit::Gigabytes))))->toHaveCount(1);
});

it('judges no cap without settings to read it from', function () use ($lifting): void {
    expect(Memory::in($lifting(Observations::none(), MemoryCap::none())))->toEqual(Findings::none())
        ->and(Memory::in(Observations::none()->withSettings(CannotJudge::because('mutation-gate.php threw.'))))
        ->toEqual(Findings::none());
});
