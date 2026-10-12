<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\InitRuns;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$makeHere = static fn(): string => (string) getcwd();

afterEach(function () use ($makeHere): void {
    $here = $makeHere();

    chdir($here);
    Scratch::sweep();
});

/**
 * `init` run in a copy of a fixture project, from the project's root as the gate runs.
 *
 * @param array<string, mixed> $input
 */
$init = static function (string $project, array $input = []): Commands {
    chdir($project);

    return Commands::run($project, 'init', ['--no-measure' => true, ...$input]);
};

/** A file of a project, or '' when it is not there. */
$file = static fn(string $project, string $path): string => is_file(sprintf('%s/%s', $project, $path))
    ? (string) file_get_contents(sprintf('%s/%s', $project, $path))
    : '';

it('writes nothing in a format it does not know', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $ran = $init($project, ['--format' => 'toml']);

    expect([$ran->code, $ran->errors])->toBe([2, "--format is php, json, yaml or neon, not \"toml\".\n"])
        ->and($file($project, 'mutation-gate.toml'))->toBe('')
        ->and($file($project, '.gitignore'))->toBe('');
});

it('writes nothing where the file --config names is in no format it writes', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $ran = $init($project, ['--config' => 'ci/gate.toml', '--format' => 'json']);

    expect([$ran->code, $ran->errors])->toBe([
        2,
        "ci/gate.toml is in no format init writes. Name a .php, .json, .yaml, .yml or .neon file.\n",
    ])->and($file($project, 'ci/gate.toml'))->toBe('')
        ->and($file($project, '.gitignore'))->toBe('');
});

/**
 * What `init` answered, and the config it wrote, where it refuses.
 *
 * @param  array<string, string|bool|null> $input
 * @param  array<string, string>           $files
 * @return list{int, string, string, string}
 */
function initCiRefused(array $input, array $files = []): array
{
    [$project, $ran] = InitRuns::inLaravel($input, $files);

    return [$ran->code, $ran->output, $ran->errors, InitRuns::file($project, 'mutation-gate.php')];
}

it('writes nothing where --ci names no CI it writes for, or where the files show none or several', function (): void {
    $refused = initCiRefused(...);
    $nothing = static fn(string $why): array => [2, '', sprintf("%s\n", $why), ''];
    $unwritten = 'init --ci writes a definition for github, gitlab, buildkite, circleci, azure, bitbucket and jenkins, not for %s.';

    expect($refused(['--ci' => null]))
        ->toBe($nothing('No CI is detected here, so init writes nothing. Name one with --ci=<name>.'))
        ->and($refused(['--ci' => null], ['.gitlab-ci.yml' => "stages: [test]\n", '.circleci/config.yml' => "version: 2.1\n"]))
        ->toBe($nothing('gitlab, circleci are all detected here, so init writes nothing. Name one with --ci=<name>.'))
        ->and($refused(['--ci' => 'teamcity']))->toBe($nothing(sprintf($unwritten, 'teamcity')))
        ->and($refused(['--ci' => 'json']))->toBe($nothing(sprintf($unwritten, 'json')))
        ->and($refused(['--ci' => 'gitlab', '--sharded' => true]))
        ->toBe($nothing('--sharded chooses GitHub\'s definition, so it takes --ci=github.'))
        ->and($refused(['--ci' => 'github', '--sharded' => true, '--single' => true]))
        ->toBe($nothing('--sharded and --single each choose one; pass one of them.'));
});

it('writes nothing for an editor it does not set up', function (): void {
    expect(initCiRefused(['--editor' => 'phpstorm']))
        ->toBe([2, '', "phpstorm is no editor init --editor sets up. Name vscode.\n", '']);
});

it('writes nothing for a hook manager it does not set up', function (): void {
    expect(initCiRefused(['--hook' => 'husky']))->toBe([
        2,
        '',
        "husky is nowhere init --hook sets hooks up. Name one of git|captainhook|grumphp|pre-commit|none.\n",
        '',
    ]);
});
