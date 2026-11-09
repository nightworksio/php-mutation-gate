<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Cli\Flow\Listings;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\HoldingSuites;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

/** Pest over a fixture project, through a shell that answers each listing so. */
function listingPest(ShellFake $shell, Project $at): Pest
{
    return new Pest($at, $shell, Patching::off(), new CapDirectory(), new PackageDirectory(), Triage::standard()->bounds());
}

it('lists the judging and the holding suites side by side as a runner that lists one after the other does', function (): void {
    $together = new ShellFake(static fn(Command $command): Ran => PestCases::listed($command));
    $after = new ShellFake(static fn(Command $command): Ran => PestCases::listed($command));
    $project = HoldingSuites::project();
    $at = PestCases::project();
    $side = Listings::of(HoldingSuites::adapters($project, HoldingSuites::checkout(), listingPest($together, $at)));
    $one = Listings::of(HoldingSuites::adapters($project, HoldingSuites::checkout(), new CoverageAsked(listingPest($after, $at), CoverageMap::of())));

    expect($side)->toEqual($one)
        ->and($together->batches())->toBe([2])
        ->and($after->batches())->toBe([])
        ->and($after->commands())->toHaveCount(2);
});

it('says why the judging suites list nothing before why the holding suites do, side by side as one after the other', function (): void {
    $failing = static fn(): ShellFake => ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));
    $project = HoldingSuites::project();
    $at = PestCases::project();
    $side = Listings::of(HoldingSuites::adapters($project, HoldingSuites::checkout(), listingPest($failing(), $at)));
    $after = $failing();
    $one = Listings::of(HoldingSuites::adapters($project, HoldingSuites::checkout(), new CoverageAsked(listingPest($after, $at), CoverageMap::of())));

    expect($side)->toEqual($one)
        ->and($after->commands())->toHaveCount(1);
});
