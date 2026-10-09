<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\Hook\PreCommitHooks;

it('is the .pre-commit-hooks.yaml the repository carries, its ids public API', function (): void {
    $carried = (string) file_get_contents(sprintf('%s/../../../../%s', __DIR__, PreCommitHooks::FILE));

    expect($carried)->toBe(sprintf("%s\n", PreCommitHooks::manifest()))
        ->and([PreCommitHooks::id(Hook::PrePush), PreCommitHooks::id(Hook::PreCommit)])
        ->toBe(['mutation-gate-pre-push', 'mutation-gate-pre-commit']);
});

it('runs each hook through the gate Composer links, never handing it file names, on the stage it is named for', function (): void {
    expect(PreCommitHooks::manifest())->toBe(<<<'YAML'
        - id: mutation-gate-pre-push
          name: mutation-gate-pre-push
          description: Judges the commits being pushed, as CI will, and blocks a push that fails.
          entry: vendor/bin/mutation-gate pre-push
          language: unsupported
          stages: [pre-push]
          pass_filenames: false
          always_run: true
          minimum_pre_commit_version: '4.4.0'
        - id: mutation-gate-pre-commit
          name: mutation-gate-pre-commit
          description: Shows the score change of each tree the commit reaches, and never blocks.
          entry: vendor/bin/mutation-gate pre-commit
          language: unsupported
          stages: [pre-commit]
          pass_filenames: false
          always_run: true
          minimum_pre_commit_version: '4.4.0'
        YAML);
});
