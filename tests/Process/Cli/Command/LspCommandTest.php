<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\LspCommand;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

$holds = [
    'holds:src/Cli/Command/LspCommand.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A binary in a directory of its own that speaks Outbreak's contract, and for
 * any other command keeps its arguments beside itself and exits with this
 * code; one that removes itself as it answers the contract where told to.
 */
function lspBinary(int $exit, bool $vanishing = false): string
{
    $binary = sprintf('%s/outbreak', Scratch::directory());
    file_put_contents($binary, sprintf(<<<'SH'
        #!/bin/sh
        if [ "$1" = contract ]; then
          %s
          printf '%%s\n' '{"outbreak":1,"version":"0.0.0"}'
          exit 0
        fi
        printf '%%s\n' "$@" > "$0.arguments"
        exit %d
        SH, $vanishing ? 'rm -f "$0"' : ':', $exit));
    chmod($binary, 0o755);

    return $binary;
}

/**
 * The exit code of `lsp`, run as the command line runs it with these words after it, in a project whose config holds
 * these members.
 */
function lspExit(string $config, string ...$words): int
{
    $application = new Application();
    $application->setAutoExit(boolean: false);
    $application->setCatchExceptions(boolean: false);
    $application->addCommand(LspCommand::command(
        FlowCommands::composition(FlowCommands::project($config), ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci()),
        '/gate/bin/mutation-gate',
    ));

    return $application->run(new ArgvInput(['mutation-gate', 'lsp', ...array_values($words)]), new NullOutput());
}

/** The config members that name this binary as Outbreak's. */
function lspBinaryNamed(string $binary): string
{
    return sprintf('"outbreak": {"binary": %s}', json_encode($binary));
}

/**
 * The arguments the binary was last run with, one per element.
 *
 * @return list<string>
 */
function lspArguments(string $binary): array
{
    return explode("\n", trim((string) file_get_contents(sprintf('%s.arguments', $binary))));
}

it('serves the editor through the binary, handing watch every option but its own and those after the double dash', function (): void {
    $binary = lspBinary(exit: 7);

    expect(lspExit(lspBinaryNamed($binary), '--severity', 'hint', '--stdio', 'src/Money.php', '--', '--whole-report'))->toBe(7)
        ->and(lspArguments($binary))->toBe([
            'lsp', '--gate', '/gate/bin/mutation-gate', '--php', PHP_BINARY, '--severity', 'hint', '--', 'src/Money.php', '--whole-report',
        ]);
})->group(...$holds);

it('shows survivors as warnings unless told otherwise, the severity given after an equals sign too', function (): void {
    $default = lspBinary(exit: 0);
    $error = lspBinary(exit: 0);

    expect(lspExit(lspBinaryNamed($default)))->toBe(0)
        ->and(array_slice(lspArguments($default), 5))->toBe(['--severity', 'warning', '--'])
        ->and(lspExit(lspBinaryNamed($error), '--severity=error'))->toBe(0)
        ->and(array_slice(lspArguments($error), 5))->toBe(['--severity', 'error', '--']);
})->group(...$holds);

it('cannot serve where no binary is in use, the binary does not start, or the config cannot be read', function (string $config, bool $vanishing, string $why): void {
    $project = FlowCommands::project(str_replace('BINARY', (string) json_encode(lspBinary(exit: 0, vanishing: $vanishing)), $config));
    set_error_handler(static fn(): bool => true);
    $ran = FlowCommands::run(LspCommand::command(FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci()), '/gate/bin/mutation-gate'));
    restore_error_handler();

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toContain($why);
})->with([
    'no binary' => ['"outbreak": {"enabled": false}', false, 'mutation-gate lsp needs the Outbreak binary, and none is in use'],
    'gone once asked' => ['"outbreak": {"binary": BINARY}', true, 'mutation-gate lsp could not start the Outbreak binary.'],
    'an invalid config' => ['"floors": 5', false, 'floors'],
])->group(...$holds);
