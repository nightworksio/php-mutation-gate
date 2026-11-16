<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\DefaultBranchSince;

it('reads since the last judged run on and off the default branch, off it alone, or never, as it says', function (): void {
    expect([
        DefaultBranchSince::Judged->readsJudged(onTheDefaultBranch: true),
        DefaultBranchSince::Judged->readsJudged(onTheDefaultBranch: false),
        DefaultBranchSince::JudgedOffBranch->readsJudged(onTheDefaultBranch: true),
        DefaultBranchSince::JudgedOffBranch->readsJudged(onTheDefaultBranch: false),
        DefaultBranchSince::Passed->readsJudged(onTheDefaultBranch: true),
        DefaultBranchSince::Passed->readsJudged(onTheDefaultBranch: false),
    ])->toBe([true, true, false, true, false, false]);
});
