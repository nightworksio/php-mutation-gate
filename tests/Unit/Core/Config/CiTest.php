<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\MergedTrust;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;

it('writes what the default branch takes from a merged pull request\'s pass as the PHP config calls it', function (): void {
    expect([
        Ci::of(trustMergedPullRequests: MergedTrust::Tree)->php(ProjectRoot::origin()),
        Ci::of(trustMergedPullRequests: MergedTrust::Off)->php(ProjectRoot::origin()),
        Ci::of(trustMergedPullRequests: MergedTrust::Delta)->php(ProjectRoot::origin()),
    ])->toEqual([
        PhpCalls::inWith('Ci::trustingMergedPullRequests()'),
        PhpCalls::inWith('Ci::notTrustingMergedPullRequests()'),
        PhpCalls::inWith('Ci::trustingMergedDeltas()'),
    ]);
});
