<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Tests\Support\Privileged;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// The action's script is Python, so it cannot read the gate's constants; each
// value it shares with the gate is held here to the gate's own.

/** The text of the action's script. */
function actionScript(): string
{
    return (string) file_get_contents(Tree::at('.github/scripts/gate_action.py'));
}

/** A string constant of the script, empty where it has none of that name. */
function scriptText(string $name): string
{
    return preg_match(sprintf('/^%s = "(?<value>[^"]*)"$/mu', $name), actionScript(), $found) === 1 ? $found['value'] : '';
}

/**
 * A frozenset constant of the script, sorted, empty where it has none of that name.
 *
 * @return list<string>
 */
function scriptSet(string $name): array
{
    preg_match(sprintf('/^%s = frozenset\(\{(?<members>[^}]*)\}\)$/mu', $name), actionScript(), $found);
    preg_match_all('/"(?<member>[^"]*)"/u', $found['members'] ?? '', $members);
    $sorted = $members['member'];
    sort($sorted);

    return $sorted;
}

/**
 * A sorted copy of a list.
 *
 * @param  list<string> $values
 * @return list<string>
 */
function sortedCopy(array $values): array
{
    sort($values);

    return $values;
}

it('keeps the ledger where the directory store keeps it by default', function (): void {
    expect(scriptText('LEDGERS'))->toBe(Workspace::ledger()->value());
});

it('names the package as Composer does', function (): void {
    expect(scriptText('GATE'))->toBe(ThisPackage::COMPOSER);
});

it('trusts the events the GitHub plan trusts to write a branch\'s ledger', function (): void {
    expect(scriptSet('TRUSTED'))->toBe(sortedCopy(GitHubPlan::TRUSTED));
});

it('refuses the events that run main\'s workflow with a token that can write', function (): void {
    expect(scriptSet('LOW_TRUST'))->toBe(sortedCopy(Privileged::EVENTS));
});

it('reads each exit code as the verdict the gate means by it', function (): void {
    preg_match('/^EXIT_CODES = \{(?<pairs>[^}]*)\}$/mu', actionScript(), $found);
    preg_match_all('/(?<code>\d+): "(?<verdict>[a-z-]+)"/u', $found['pairs'] ?? '', $pairs);
    $said = array_combine(array_map(intval(...), $pairs['code']), $pairs['verdict']);

    expect($said)->toBe([
        ExitCode::Passed->value => Judgement::Passed->value,
        ExitCode::Failed->value => Judgement::Failed->value,
        ExitCode::CannotJudge->value => Judgement::CannotJudge->value,
    ]);
});
