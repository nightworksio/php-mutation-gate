<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Keyed\DefaultBranch;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('takes the default branch the event payload names, else the variable\'s', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'event.json', '{"repository": {"default_branch": "trunk"}}');
    $payload = sprintf('%s/event.json', $directory);

    expect(DefaultBranch::scopeIn(Variables::of(['GITHUB_EVENT_PATH' => $payload, 'MUTATION_GATE_DEFAULT_BRANCH' => 'main'])))
        ->toEqual(Scope::branch('trunk'))
        ->and(DefaultBranch::scopeIn(Variables::of(['GITHUB_EVENT_PATH' => sprintf('%s/none.json', $directory), 'MUTATION_GATE_DEFAULT_BRANCH' => 'main'])))
        ->toEqual(Scope::branch('main'))
        ->and(DefaultBranch::scopeIn(Variables::of(['MUTATION_GATE_DEFAULT_BRANCH' => 'release/2'])))
        ->toEqual(Scope::branch('release/2'));
});

it('names no default branch where neither names one, or one that is no branch', function (): void {
    expect(DefaultBranch::scopeIn(Variables::of([])))
        ->toEqual(CannotJudge::because('Neither the event payload nor MUTATION_GATE_DEFAULT_BRANCH names the default branch.'))
        ->and(DefaultBranch::scopeIn(Variables::of(['MUTATION_GATE_DEFAULT_BRANCH' => 'main/../x'])))
        ->toEqual(CannotJudge::because('"refs/heads/main/../x" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.'));
});
