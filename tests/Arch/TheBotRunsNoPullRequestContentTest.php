<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Privileged;

// V5: a workflow that issue_comment, pull_request_target or workflow_run
// starts runs main's copy with a token that can write, so it runs nothing a
// pull request brings (ADR-0019, decision 3). Its jobs check out main and run
// the stdlib scripts, and a comment's or a pull request's text reaches those
// only through env.

/** A checkout of main at the event's own commit, which leaves no token behind. */
const CHECKOUT = '#^actions/checkout@[0-9a-f]{40}$#';

/** The inputs a checkout of main may take: none of them picks another ref or repository. */
const CHECKOUT_INPUTS = ['persist-credentials', 'sparse-checkout'];

/** The App's token, minted by the pinned action. */
const TOKEN_ACTION = '#^actions/create-github-app-token@[0-9a-f]{40}$#';

/** One stdlib script of main, with plain words as its arguments and no expression. */
const SCRIPT = '#^python3 \.github/scripts/[a-z_]+\.py(?: [a-z-]+)*$#';

/**
 * What is wrong with one step of a job that holds a writing token.
 *
 * @param array{uses: string, run: string, with: array<array-key, Node>} $step
 */
function faultOf(array $step): string
{
    $inputs = array_map(strval(...), array_keys($step['with']));
    $keepsNoToken = array_key_exists('persist-credentials', $step['with']) && ! Lenient::boolean($step['with']['persist-credentials'], otherwise: true);

    return match (true) {
        preg_match(CHECKOUT, $step['uses']) === 1 => match (true) {
            array_diff($inputs, CHECKOUT_INPUTS) !== [] => sprintf('checks out with %s, which can pick what is not main', implode(', ', array_diff($inputs, CHECKOUT_INPUTS))),
            ! $keepsNoToken => 'checks out without persist-credentials: false',
            default => '',
        },
        preg_match(TOKEN_ACTION, $step['uses']) === 1 => '',
        $step['uses'] !== '' => sprintf('uses %s', $step['uses']),
        preg_match(SCRIPT, $step['run']) === 1 => '',
        default => sprintf('runs "%s", which is no stdlib script of main with plain arguments', $step['run']),
    };
}

it('runs no pull request content where it can write', function (): void {
    $wrong = [];

    foreach (Privileged::workflows() as $path => $workflow) {
        $wrong[] = $workflow['permissions'] ? '' : sprintf('%s: its permissions are not {}', $path);

        foreach ($workflow['jobs'] as $id => $job) {
            $wrong[] = match (true) {
                $job['calls'] => sprintf('%s: %s calls another workflow', $path, $id),
                ! $job['permissions'] => sprintf('%s: %s does not name the permissions it holds', $path, $id),
                default => '',
            };

            foreach ($job['steps'] as $step) {
                $fault = faultOf($step);
                $wrong[] = $fault === '' ? '' : sprintf('%s: %s %s', $path, $id, $fault);
            }
        }
    }

    $wrong = array_values(array_unique(array_filter($wrong, static fn(string $one): bool => $one !== '')));

    // V5
    expect($wrong)->toBe([], sprintf(
        "These workflows run with a token that can write, and could run what a pull request brings:\n  %s\n\nA job started by %s holds only the permissions it names, and runs only a checkout of main, the stdlib scripts and the pinned token action, with no expression in a run (V5).",
        implode("\n  ", $wrong),
        implode(', ', Privileged::EVENTS),
    ));
});

it('finds the workflows the bot runs with a token that can write', function (): void {
    expect(array_keys(Privileged::workflows()))->toContain('.github/workflows/bot-commands.yml')
        ->and(array_keys(Privileged::workflows()))->not->toContain('.github/workflows/ci.yml', '.github/workflows/pr.yml');
});
