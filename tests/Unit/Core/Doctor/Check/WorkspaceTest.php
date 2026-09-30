<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\Workspace;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('advises keeping the gate\'s directory out of git where .gitignore leaves it in', function (): void {
    expect(Workspace::in(Observations::none()->withGitIgnore(GitIgnore::of("/vendor/\n"))))->toEqual(Findings::of(Finding::of(
        Slug::WorkspaceNotIgnored,
        Severity::Advice,
        '.gitignore does not name .mutation-gate/.',
        'The gate keeps each run\'s coverage maps, results and ledger there, and none of them belongs in git.',
        'Add the line .mutation-gate/ to .gitignore, as mutation-gate init does.',
    )));
});

it('finds nothing where .gitignore names it, or nothing was observed', function (): void {
    expect(Workspace::in(Observations::none()->withGitIgnore(GitIgnore::of(".mutation-gate/\n"))))->toEqual(Findings::none())
        ->and(Workspace::in(Observations::none()))->toEqual(Findings::none());
});
