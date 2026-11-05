<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Serve\ServedVariables;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;

it('sends only the pre-commit framework\'s push variables and the terminal\'s size, never a token or anything else', function (): void {
    expect(ServedVariables::of([
        'PRE_COMMIT_FROM_REF' => 'a',
        'PRE_COMMIT_TO_REF' => 'b',
        'PRE_COMMIT_LOCAL_BRANCH' => 'refs/heads/x',
        'PRE_COMMIT_REMOTE_BRANCH' => 'refs/heads/y',
        'COLUMNS' => '120',
        'LINES' => '40',
        'MUTATION_GATE_GCS_TOKEN' => 'secret',
        'GITHUB_TOKEN' => 'secret',
        'HOME' => '/home/me',
    ]))->toBe([
        'PRE_COMMIT_FROM_REF' => 'a',
        'PRE_COMMIT_TO_REF' => 'b',
        'PRE_COMMIT_LOCAL_BRANCH' => 'refs/heads/x',
        'PRE_COMMIT_REMOTE_BRANCH' => 'refs/heads/y',
        'COLUMNS' => '120',
        'LINES' => '40',
    ])->and(ServedVariables::names())->toBe([
        'PRE_COMMIT_FROM_REF',
        'PRE_COMMIT_TO_REF',
        'PRE_COMMIT_LOCAL_BRANCH',
        'PRE_COMMIT_REMOTE_BRANCH',
        'COLUMNS',
        'LINES',
    ]);
});

it('lets watch start a daemon only under local.serveStart: watch', function (): void {
    expect(ServeStart::Watch->startsForWatch())->toBeTrue()
        ->and(ServeStart::Never->startsForWatch())->toBeFalse();
});
