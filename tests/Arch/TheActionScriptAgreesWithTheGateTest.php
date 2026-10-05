<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Privileged;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

// The action's script is Python, so it cannot read the gate's constants; each
// value it shares with the gate is held here to the gate's own.

/** The text of the action's script. */
function actionScript(): string
{
    return (string) file_get_contents(Tree::at('resources/action/gate_action.py'));
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

it('reads the installed gate\'s version where the gate reads what Composer installed', function (): void {
    expect(scriptText('INSTALLED'))->toBe(Installed::fileIn(Path::of(Manifest::VENDOR))->value());
});

it('names the Pest runner by the word the config chooses it by', function (): void {
    expect(scriptText('PEST'))->toBe(BuiltinRunner::Pest->value);
});

it('names the package as Composer does', function (): void {
    expect(scriptText('GATE'))->toBe(ThisPackage::COMPOSER);
});

it('publishes the config schema under its release line\'s tag, which the default set requires', function (): void {
    $line = scriptText('LINE');
    $manifest = (string) file_get_contents(Tree::at('plugins/default/composer.json'));

    expect($line)->not->toBe('')
        ->and(Decoded::at(Definition::schema(), '$id'))->toContain(sprintf('/php-mutation-gate/v%s/', $line))
        ->and(Decoded::at($manifest, 'require', ThisPackage::COMPOSER))->toBe(sprintf('^%s', $line));
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

it('reads this package\'s effective config as the gate prints it: the ledger in the cached directory, and Pest patched', function (): void {
    $shown = new Process([PHP_BINARY, 'bin/mutation-gate', 'config:show', '--format=json'], Tree::root());
    $shown->mustRun();
    $output = sprintf('%s/output', Scratch::directory());
    touch($output);
    $variables = ['GITHUB_OUTPUT' => $output, 'CACHE' => 'true'];
    new Process(['python3', 'resources/action/gate_action.py', 'config'], Tree::root(), $variables, $shown->getOutput())->mustRun();

    expect((string) file_get_contents($output))->toBe("default_store=true\npest_patch=true\n");
});
