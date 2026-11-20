<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\CgroupFile;
use NightWorksIO\MutationGate\Adapter\Process\CgroupKnob;
use NightWorksIO\MutationGate\Adapter\Process\Cgroups;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv(Cgroups::VARIABLE);
    Scratch::sweep();
});

/** A directory standing for a cgroup, with the files its knobs write, each holding 0. */
function cgroupFileGroup(string $path): string
{
    mkdir($path, recursive: true);

    foreach ([CgroupKnob::Kill, CgroupKnob::OomGroup] as $knob) {
        file_put_contents($knob->of($path), '0');
    }

    return $path;
}

it('kills only a cgroup strictly inside the gate\'s own', function (string $under, bool $killed): void {
    $root = (string) realpath(Scratch::directory());
    $gate = cgroupFileGroup(sprintf('%s/gate', $root));
    cgroupFileGroup(sprintf('%s/gate/started-0', $root));
    cgroupFileGroup(sprintf('%s/beside', $root));
    putenv(sprintf('%s=%s', Cgroups::VARIABLE, $gate));
    $cgroup = sprintf('%s/%s', $root, $under);

    expect(CgroupFile::wrote($cgroup, CgroupKnob::Kill, '1'))->toBe($killed)
        ->and(file_get_contents(CgroupKnob::Kill->of($cgroup)))->toBe($killed ? '1' : '0');
})->with([
    'a run under the gate\'s' => ['gate/started-0', true],
    'the gate\'s own' => ['gate', false],
    'one beside it' => ['beside', false],
    'one that leads back out of it' => ['gate/started-0/..', false],
]);

it('kills no cgroup where this process was told no cgroup of its own', function (): void {
    $run = cgroupFileGroup(sprintf('%s/gate/started-0', realpath(Scratch::directory())));

    expect(CgroupFile::wrote($run, CgroupKnob::Kill, '1'))->toBeFalse()
        ->and(CgroupFile::isTheGates($run))->toBeFalse();
});

it('writes every other file of a cgroup wherever it lies', function (): void {
    $root = (string) realpath(Scratch::directory());
    putenv(sprintf('%s=%s', Cgroups::VARIABLE, cgroupFileGroup(sprintf('%s/gate', $root))));
    $beside = cgroupFileGroup(sprintf('%s/beside', $root));

    expect(CgroupFile::wrote($beside, CgroupKnob::OomGroup, '1'))->toBeTrue()
        ->and(file_get_contents(CgroupKnob::OomGroup->of($beside)))->toBe('1');
});
