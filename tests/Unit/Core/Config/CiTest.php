<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;

it('writes whether a merged pull request\'s proofs are trusted as the PHP config calls it', function (): void {
    expect([Ci::of(trustMergedPullRequests: true)->php(ProjectRoot::origin()), Ci::of(trustMergedPullRequests: false)->php(ProjectRoot::origin())])
        ->toEqual([PhpCalls::inWith('Ci::trustingMergedPullRequests()'), PhpCalls::inWith('Ci::notTrustingMergedPullRequests()')]);
});
