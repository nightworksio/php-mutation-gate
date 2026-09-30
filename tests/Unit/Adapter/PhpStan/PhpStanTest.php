<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStan;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\FakeAnalyser;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
    putenv('MUTATION_GATE_CONTRACT_TOKEN');
});

/** PHPStan in a project, reading the config the gate hands it, if any. */
function phpstanIn(string $project, string $options = '{}'): PhpStan
{
    $phpstan = PhpStan::fromOptions(Configs::options($options), $project);

    return $phpstan instanceof PhpStan ? $phpstan : throw new LogicException('No PHPStan.');
}

it('names its version and the digest of the config it reads', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');

    expect(phpstanIn($project)->identity(Withheld::standard()))
        ->toEqual(AnalyserIdentity::of('phpstan', '2.2.16', Digest::sha256Of("parameters:\n    level: 9\n")));
});

it('reads the config the gate hands it before the one it finds', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'build/phpstan.neon', 'parameters: {}');
    $phpstan = phpstanIn($project, '{"config": "build/phpstan.neon"}');
    Scratch::write($project, 'vendor/bin/answer.json', '{"totals": {}, "files": {}, "errors": []}');
    Scratch::write($project, 'vendor/bin/answer.exit', '0');

    $phpstan->findings(Paths::none(), Withheld::standard());

    expect($phpstan->identity(Withheld::standard()))
        ->toEqual(AnalyserIdentity::of('phpstan', '2.2.16', Digest::sha256Of('parameters: {}')))
        ->and(file_get_contents(sprintf('%s/.mutation-gate/phpstan/check.neon', $project)))
        ->toBe(sprintf("includes:\n    - %s/build/phpstan.neon\nparameters:\n    parallel:\n        maximumNumberOfProcesses: 1\n    reportUnmatchedIgnoredErrors: false\n", $project));
});

it('refuses a config option that is no path', function (): void {
    expect(PhpStan::fromOptions(Configs::options('{"config": 5}'), Scratch::directory()))
        ->toEqual(Invalid::because(Problem::at('config', 'expected a path, got 5')));
});

it('cannot judge without a config, one it cannot read, or a version it does not say', function (): void {
    $bare = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    unlink(sprintf('%s/phpstan.neon', $bare));
    $unreadable = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    $silent = FakeAnalyser::phpstan('');

    expect(phpstanIn($bare)->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan has no config to read: add a phpstan.neon, or name one in staticCheck.config.'))
        ->and(phpstanIn($bare)->findings(Paths::none(), Withheld::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and(phpstanIn($unreadable, '{"config": "missing.neon"}')->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan\'s config missing.neon cannot be read.'))
        ->and(phpstanIn($silent)->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan did not say its version (exit 0: ).'));
});

it('analyses every file the config names for the originals, and a mutant in its original\'s place', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'vendor/bin/answer.json', '{"totals": {}, "files": {"/p/src/Money.php": {"messages": [{"message": "Method add() should return int but returns string.", "identifier": "return.type"}]}}, "errors": []}');
    Scratch::write($project, 'vendor/bin/answer.exit', '1');
    $phpstan = phpstanIn($project);
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))->withholding(Withheld::of('MUTATION_GATE_CONTRACT_TOKEN'));
    putenv('MUTATION_GATE_CONTRACT_TOKEN=leaked');

    expect($phpstan->check($check))->toEqual(Findings::of(Finding::error('return.type', 'Method add() should return int but returns string.')))
        ->and(explode("\n", (string) file_get_contents(sprintf('%s/vendor/bin/argv.txt', $project))))->toBe([
            'analyse',
            sprintf('--configuration=%s/.mutation-gate/phpstan/check.neon', $project),
            '--error-format=json',
            '--no-progress',
            '--tmp-file=/tmp/mutant.php',
            sprintf('--instead-of=%s/src/Money.php', $project),
            'withheld',
        ])
        ->and($phpstan->findings(Paths::of(Path::of('src/Money.php')), Withheld::standard()))->toBeInstanceOf(Findings::class)
        ->and(file_get_contents(sprintf('%s/vendor/bin/argv.txt', $project)))->toEndWith("--no-progress\nleaked");
});

it('cannot judge where it cannot write its own config', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, '.mutation-gate/phpstan', 'a file where the directory belongs');

    expect(phpstanIn($project)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('The gate cannot write PHPStan\'s config for its checks to %s/.mutation-gate/phpstan/check.neon.', $project)));
});
