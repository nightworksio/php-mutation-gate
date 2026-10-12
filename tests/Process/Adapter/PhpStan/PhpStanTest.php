<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\FakeAnalyser;
use NightWorksIO\MutationGate\Tests\Support\PhpStanProjects;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/PhpStan/CheckLimit.php',
    'holds:src/Adapter/PhpStan/PhpStan.php',
    'holds:src/Adapter/PhpStan/Scope.php',
    'holds:src/Core/Time/Seconds.php',
];

afterEach(function (): void {
    Scratch::sweep();
    putenv('MUTATION_GATE_CONTRACT_TOKEN');
});

it('names its version and the digest of the config it reads', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');

    expect(PhpStanProjects::in($project)->identity(Withheld::standard()))
        ->toEqual(AnalyserIdentity::of('phpstan', '2.2.16', Digest::sha256Of("parameters:\n    level: 9\n")));
})->group(...$holds);

it('reads the config the gate hands it before the one it finds', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'build/phpstan.neon', 'parameters: {}');
    $phpstan = PhpStanProjects::in($project, '{"config": "build/phpstan.neon"}');
    Scratch::write($project, 'vendor/bin/answer.json', '{"totals": {}, "files": {}, "errors": []}');
    Scratch::write($project, 'vendor/bin/answer.exit', '0');

    $phpstan->findings(Paths::none(), Withheld::standard());

    expect($phpstan->identity(Withheld::standard()))
        ->toEqual(AnalyserIdentity::of('phpstan', '2.2.16', Digest::sha256Of('parameters: {}')))
        ->and(file_get_contents(sprintf('%s/.mutation-gate/phpstan/check.neon', $project)))
        ->toBe(sprintf("includes:\n    - %s/build/phpstan.neon\nparameters:\n    parallel:\n        maximumNumberOfProcesses: 1\n    reportUnmatchedIgnoredErrors: false\n", $project));
})->group(...$holds);

/** What PHPStan in this project says it runs with, where its parameters name its own root and this machine's. */
function phpstanSettingsIn(string $project, string $machine): AnalyserSettings|CannotJudge
{
    Scratch::write($project, 'vendor/bin/params.json', sprintf(
        '{"level": 9, "paths": ["%1$s/src"], "tmpDir": "/tmp/%2$s", "sysGetTempDir": "/tmp", "resultCachePath": "/tmp/%2$s/r.php",'
        . ' "env": {"HOME": "/home/%2$s"}, "pro": {"tmpDir": "/tmp/%2$s"},'
        . ' "allConfigFiles": ["phar://%1$s/vendor/phpstan/phpstan/phpstan.phar/conf/config.neon", "%1$s/phpstan.neon", "%1$s/phpstan-baseline.neon"],'
        . ' "bootstrapFiles": ["%1$s/tests/bootstrap.php"], "stubFiles": ["%1$s/stubs/a.stub"], "scanFiles": ["%1$s/lib/x.php"],'
        . ' "scanDirectories": ["%1$s/legacy"]}',
        $project,
        $machine,
    ));

    return PhpStanProjects::in($project)->configuration(Withheld::standard());
}

it('says the parameters PHPStan resolves alike from two roots and machines, with every file they name but its own', function (): void {
    $here = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    $settings = phpstanSettingsIn($here, 'ada');
    $there = phpstanSettingsIn(FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16'), 'runner');

    expect($settings)->toBeInstanceOf(AnalyserSettings::class)
        ->and($settings instanceof AnalyserSettings ? $settings->written() : '')->toBe($there instanceof AnalyserSettings ? $there->written() : 'none')
        ->and($settings instanceof AnalyserSettings ? $settings->written() : '')->not->toContain('/tmp')
        ->and($settings instanceof AnalyserSettings ? $settings->references() : $settings)->toEqual(Paths::of(
            Path::of('phpstan.neon'),
            Path::of('phpstan-baseline.neon'),
            Path::of('tests/bootstrap.php'),
            Path::of('stubs/a.stub'),
            Path::of('lib/x.php'),
            Path::of('legacy'),
        ))
        ->and(explode("\n", (string) file_get_contents(sprintf('%s/vendor/bin/argv.txt', $here))))
        ->toBe(['dump-parameters', '--json', sprintf('--configuration=%s/phpstan.neon', $here), 'withheld']);
})->group(...$holds);

it('cannot say the configuration where it cannot dump its parameters, dumps no object, or has no config to read', function (): void {
    $failing = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($failing, 'vendor/bin/params.exit', '1');
    $garbled = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($garbled, 'vendor/bin/params.json', 'Deprecated: something');
    $unconfigured = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    unlink(sprintf('%s/phpstan.neon', $unconfigured));

    expect(PhpStanProjects::in($failing)->configuration(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan could not say the configuration it runs with (exit 1: ).'))
        ->and(PhpStanProjects::in($garbled)->configuration(Withheld::standard()))
        ->toEqual(CannotJudge::because('The analyser\'s resolved configuration is no object: null'))
        ->and(PhpStanProjects::in($unconfigured)->configuration(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan has no config to read: add a phpstan.neon, or name one in staticCheck.config.'));
})->group(...$holds);

it('cannot judge without a config, one it cannot read, or a version it does not say', function (): void {
    $bare = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    unlink(sprintf('%s/phpstan.neon', $bare));
    $unreadable = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    $silent = FakeAnalyser::phpstan('');

    expect(PhpStanProjects::in($bare)->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan has no config to read: add a phpstan.neon, or name one in staticCheck.config.'))
        ->and(PhpStanProjects::in($bare)->findings(Paths::none(), Withheld::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and(PhpStanProjects::in($unreadable, '{"config": "missing.neon"}')->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan\'s config missing.neon cannot be read.'))
        ->and(PhpStanProjects::in($silent)->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan did not say its version (exit 0: ).'));
})->group(...$holds);

it('analyses every file the config names for the originals, and a mutant in its original\'s place', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'vendor/bin/answer.json', sprintf('{"totals": {}, "files": {"%s/src/Money.php": {"messages": [{"message": "Method add() should return int but returns string.", "identifier": "return.type"}]}}, "errors": []}', $project));
    Scratch::write($project, 'vendor/bin/answer.exit', '1');
    Scratch::write($project, 'src/Money.php', '<?php');
    Scratch::write($project, 'vendor/bin/params.json', sprintf('{"paths": ["%s/src"]}', $project));
    $phpstan = PhpStanProjects::in($project);
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))->withholding(Withheld::of('MUTATION_GATE_CONTRACT_TOKEN'));
    $argv = static fn(): string => (string) file_get_contents(sprintf('%s/vendor/bin/argv.txt', $project));
    putenv('MUTATION_GATE_CONTRACT_TOKEN=leaked');

    $warm = $phpstan->findings(Paths::of(Path::of('src/Money.php')), Withheld::standard());
    $warmedWith = $argv();
    $checked = $phpstan->check($check);

    expect($checked)->toEqual(Findings::of(Finding::error(Path::of('src/Money.php'), 'return.type', 'Method add() should return int but returns string.')))
        ->and(explode("\n", $argv()))->toBe([
            'analyse',
            sprintf('--configuration=%s/.mutation-gate/phpstan/check.neon', $project),
            '--error-format=json',
            '--no-progress',
            '--tmp-file=/tmp/mutant.php',
            sprintf('--instead-of=%s/src/Money.php', $project),
            'withheld',
        ])
        ->and($warm)->toBeInstanceOf(Findings::class)
        ->and($warmedWith)->toEndWith("--no-progress\nleaked")
        ->and(file_get_contents(sprintf('%s/.mutation-gate/phpstan/scope.json', $project)))
        ->toBe(sprintf('{"paths": ["%s/src"]}', $project));
})->group(...$holds);

it('places each finding in the file it sits in, one in the mutant\'s own file in the original it stands in for', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'vendor/bin/answer.json', sprintf(
        '{"totals": {}, "files": {"%s": {"messages": [%s]}, "%s/src/Wallet.php": {"messages": [%s]}}, "errors": []}',
        '/tmp/mutant.php',
        '{"message": "Method add() should return int but returns string.", "identifier": "return.type"}',
        $project,
        '{"message": "Method total() should return int but returns string.", "identifier": "return.type"}',
    ));
    Scratch::write($project, 'vendor/bin/answer.exit', '1');
    Scratch::write($project, 'src/Money.php', '<?php');
    Scratch::write($project, 'vendor/bin/params.json', sprintf('{"paths": ["%s/src"]}', $project));
    $phpstan = PhpStanProjects::in($project);
    $phpstan->findings(Paths::none(), Withheld::standard());

    expect($phpstan->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))))->toEqual(Findings::of(
        Finding::error(Path::of('src/Money.php'), 'return.type', 'Method add() should return int but returns string.'),
        Finding::error(Path::of('src/Wallet.php'), 'return.type', 'Method total() should return int but returns string.'),
    ));
})->group(...$holds);

it('leaves a mutant out of its scope where its original is outside the paths it analyses, or excluded', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'vendor/bin/params.json', sprintf(
        '{"paths": ["%1$s/src"], "excludePaths": {"analyseAndScan": ["%1$s/src/Legacy"], "analyse": ["%1$s/src/*.gen.php"]}}',
        $project,
    ));
    Scratch::write($project, 'vendor/bin/answer.json', '{"totals": {}, "files": {}, "errors": []}');
    Scratch::write($project, 'src/Money.php', '<?php');
    $phpstan = PhpStanProjects::in($project);
    $phpstan->findings(Paths::none(), Withheld::standard());
    $checked = static fn(string $file): Findings|OutOfScope|CannotJudge => $phpstan->check(MutantCheck::of(Path::of($file), Path::of('/tmp/mutant.php')));

    expect($checked('lib/Other.php'))->toEqual(OutOfScope::of(Path::of('lib/Other.php')))
        ->and($checked('src/Legacy/Old.php'))->toEqual(OutOfScope::of(Path::of('src/Legacy/Old.php')))
        ->and($checked('src/Money.gen.php'))->toEqual(OutOfScope::of(Path::of('src/Money.gen.php')))
        ->and($checked('src/Money.php'))->toEqual(Findings::none());
})->group(...$holds);

it('reads a file as PHPStan walks to it, through a linked directory or a root that is a link, and not as it really is', function (): void {
    $real = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($real, 'shared/B.php', '<?php');
    Scratch::write($real, 'vendor/bin/answer.json', '{"totals": {}, "files": {}, "errors": []}');
    mkdir(sprintf('%s/src', $real));
    symlink('../shared', sprintf('%s/src/Linked', $real));
    $linked = sprintf('%s-link', $real);
    symlink($real, $linked);
    Scratch::write($real, 'vendor/bin/params.json', sprintf('{"paths": ["%s/src"]}', $linked));
    $phpstan = PhpStanProjects::in($linked);
    $phpstan->findings(Paths::none(), Withheld::standard());
    $checked = $phpstan->check(MutantCheck::of(Path::of('src/Linked/B.php'), Path::of('/tmp/mutant.php')));
    $shared = $phpstan->check(MutantCheck::of(Path::of('shared/B.php'), Path::of('/tmp/mutant.php')));
    unlink($linked);

    expect($checked)->toEqual(Findings::none())
        ->and($shared)->toEqual(OutOfScope::of(Path::of('shared/B.php')));
})->group(...$holds);

it('never reads a scope an earlier warm-up kept once a later one cannot say its own', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, '.mutation-gate/phpstan/scope.json', sprintf('{"paths": ["%s/src"]}', $project));
    Scratch::write($project, 'vendor/bin/params.exit', '1');
    $phpstan = PhpStanProjects::in($project);

    expect($phpstan->findings(Paths::none(), Withheld::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and(is_file(sprintf('%s/.mutation-gate/phpstan/scope.json', $project)))->toBeFalse()
        ->and($phpstan->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))))
        ->toEqual(CannotJudge::because('PHPStan\'s run over the originals has not said which files it analyses.'));
})->group(...$holds);

it('cannot warm up where it cannot remove the scope an earlier warm-up kept', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    $phpstan = PhpStanProjects::in($project);
    $phpstan->findings(Paths::none(), Withheld::standard());
    $directory = sprintf('%s/.mutation-gate/phpstan', $project);
    chmod($directory, 0o555);
    $warmed = $phpstan->findings(Paths::none(), Withheld::standard());
    chmod($directory, 0o755);

    expect($warmed)->toEqual(CannotJudge::because(
        sprintf('The gate cannot write PHPStan\'s config for its checks to %s/scope.json.', $directory),
    ));
})->group(...$holds);

it('cannot check where its warm-up never said which files it analyses, or could not', function (): void {
    $unwarmed = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    $failing = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($failing, 'vendor/bin/params.json', 'no parameters');
    Scratch::write($failing, 'vendor/bin/params.exit', '1');
    $garbled = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($garbled, 'vendor/bin/params.json', '{"paths": "src"}');
    $unkept = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($unkept, '.mutation-gate/phpstan/scope.json/blocked', '');
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'));

    expect(PhpStanProjects::in($unwarmed)->check($check))
        ->toEqual(CannotJudge::because('PHPStan\'s run over the originals has not said which files it analyses.'))
        ->and(PhpStanProjects::in($failing)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan could not say which files it analyses (exit 1: ).'))
        ->and(PhpStanProjects::in($garbled)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan did not say which files it analyses: the parameters.paths is not a list.'))
        ->and(PhpStanProjects::in($unkept)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('The gate cannot write PHPStan\'s config for its checks to %s/.mutation-gate/phpstan/scope.json.', $unkept)));
})->group(...$holds);

it('cannot judge a check that takes longer than its limit, stopping it there', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'vendor/bin/answer.json', '{"totals": {}, "files": {}, "errors": []}');
    Scratch::write($project, 'src/Money.php', '<?php');
    Scratch::write($project, 'vendor/bin/params.json', sprintf('{"paths": ["%s/src"]}', $project));
    $phpstan = PhpStanProjects::in($project);
    $phpstan->findings(Paths::none(), Withheld::standard());
    Scratch::write($project, 'vendor/bin/answer.sleep', '30');
    $started = microtime(as_float: true);

    $checked = $phpstan->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))->within(Seconds::of(1.0)));

    expect($checked)->toEqual(CannotJudge::because('PHPStan did not finish a check in 1s.'))
        ->and(microtime(as_float: true) - $started)->toBeLessThan(20.0);
})->group(...$holds);
