<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Keyed\TrustedRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

/**
 * The scope deliver may write in a GitHub Actions run of this event and ref, with this event payload and these
 * variables besides.
 *
 * @param array<string, string> $more
 */
function trustedRunOf(string $event, string $ref, string $payload, array $more = []): Scope|NotWritten
{
    $directory = Scratch::directory();
    Scratch::write($directory, 'event.json', $payload);

    return TrustedRun::scopeIn(Variables::of([
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_EVENT_NAME' => $event,
        'GITHUB_REF' => $ref,
        'GITHUB_EVENT_PATH' => sprintf('%s/event.json', $directory),
        ...$more,
    ]));
}

const TRUSTED_RUN_MAIN = '{"repository": {"default_branch": "main"}}';

const TRUSTED_RUN_REFUSED = 'This run is no push, schedule or manual run of the default branch, so deliver writes no ledger.';

it('writes the default branch\'s scope on a push, a schedule or a manual run of that branch', function (string $event): void {
    expect(trustedRunOf($event, 'refs/heads/main', TRUSTED_RUN_MAIN))->toEqual(Scope::branch('main'));
})->with(['push', 'schedule', 'workflow_dispatch']);

it('writes no scope on any other run', function (string $event, string $ref, string $payload): void {
    expect(trustedRunOf($event, $ref, $payload))->toEqual(NotWritten::because(TRUSTED_RUN_REFUSED));
})->with([
    'a push of another branch' => ['push', 'refs/heads/feature', TRUSTED_RUN_MAIN],
    'a tag' => ['push', 'refs/tags/main', TRUSTED_RUN_MAIN],
    'a pull request' => ['pull_request', 'refs/pull/7/merge', '{"repository": {"default_branch": "main"}, "pull_request": {"number": 7}}'],
    'a pull request\'s target' => ['pull_request_target', 'refs/heads/main', '{"repository": {"default_branch": "main"}, "pull_request": {"number": 7}}'],
    'a merge group' => ['merge_group', 'refs/heads/gh-readonly-queue/main/pr-7', TRUSTED_RUN_MAIN],
    'a release' => ['release', 'refs/heads/main', TRUSTED_RUN_MAIN],
    'a payload naming no default branch' => ['push', 'refs/heads/main', '{"repository": {}}'],
    'a payload naming an empty one' => ['push', 'refs/heads/', '{"repository": {"default_branch": ""}}'],
    'no payload' => ['push', 'refs/heads/main', 'not json'],
]);

it('takes the default branch from MUTATION_GATE_DEFAULT_BRANCH where a schedule\'s payload names none', function (): void {
    expect(trustedRunOf('schedule', 'refs/heads/main', '{"schedule": "0 3 * * 1"}', ['MUTATION_GATE_DEFAULT_BRANCH' => 'main']))
        ->toEqual(Scope::branch('main'))
        ->and(trustedRunOf('schedule', 'refs/heads/main', '{"repository": {"default_branch": ""}}', ['MUTATION_GATE_DEFAULT_BRANCH' => 'main']))
        ->toEqual(Scope::branch('main'))
        ->and(trustedRunOf('schedule', 'refs/heads/main', '{"schedule": "0 3 * * 1"}'))
        ->toEqual(NotWritten::because(TRUSTED_RUN_REFUSED))
        ->and(trustedRunOf('schedule', 'refs/heads/main', '{"schedule": "0 3 * * 1"}', ['MUTATION_GATE_DEFAULT_BRANCH' => 'trunk']))
        ->toEqual(NotWritten::because(TRUSTED_RUN_REFUSED));
});

it('trusts the payload\'s default branch over the variable', function (): void {
    expect(trustedRunOf('push', 'refs/heads/main', TRUSTED_RUN_MAIN, ['MUTATION_GATE_DEFAULT_BRANCH' => 'feature']))
        ->toEqual(Scope::branch('main'))
        ->and(trustedRunOf('push', 'refs/heads/feature', TRUSTED_RUN_MAIN, ['MUTATION_GATE_DEFAULT_BRANCH' => 'feature']))
        ->toEqual(NotWritten::because(TRUSTED_RUN_REFUSED));
});

it('writes no scope outside GitHub Actions', function (): void {
    expect(TrustedRun::scopeIn(Variables::of(['GITHUB_EVENT_NAME' => 'push', 'GITHUB_REF' => 'refs/heads/main'])))
        ->toEqual(NotWritten::because('deliver writes a ledger only in GitHub Actions, so this run writes none.'));
});
