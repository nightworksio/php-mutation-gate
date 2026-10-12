<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\GitHooks;
use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A directory of its own, a git repository where asked. */
function gitHooksProject(bool $repository): string
{
    $project = Scratch::directory();

    if ($repository) {
        exec(sprintf('git -C %s init --quiet', escapeshellarg($project)));
    }

    return $project;
}

it('writes git\'s own pre-push hook as hook install does, and says so', function (): void {
    $project = gitHooksProject(repository: true);

    $made = GitHooks::made($project, Output::Written);

    expect($made)->toBe(sprintf('Wrote %s/.git/hooks/pre-push.', realpath($project)))
        ->and((string) file_get_contents(sprintf('%s/.git/hooks/pre-push', $project)))
        ->toContain("exec vendor/bin/mutation-gate pre-push \"\$@\"\n");
});

it('prints the hook whole with --dry-run, and writes none', function (): void {
    $project = gitHooksProject(repository: true);

    $made = GitHooks::made($project, Output::Printed);

    expect($made)->toStartWith(sprintf("%s/.git/hooks/pre-push:\n\n#!/bin/sh\n", realpath($project)))
        ->and(is_file(sprintf('%s/.git/hooks/pre-push', $project)))->toBeFalse();
});

it('says why it cannot set git\'s hooks up outside a repository', function (): void {
    $made = GitHooks::made(gitHooksProject(repository: false), Output::Written);

    expect($made)->toBeInstanceOf(CannotJudge::class)
        ->and($made instanceof CannotJudge ? $made->why() : '')->toContain('not a git repository');
});
