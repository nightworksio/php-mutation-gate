<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStan;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
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
    $phpstan = PhpStan::fromOptions(Configs::options($options), $project, new LocalProcesses(new SystemClock()));

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

    return phpstanIn($project)->configuration(Withheld::standard());
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
});

it('cannot say the configuration where it cannot dump its parameters, dumps no object, or has no config to read', function (): void {
    $failing = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($failing, 'vendor/bin/params.exit', '1');
    $garbled = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($garbled, 'vendor/bin/params.json', 'Deprecated: something');
    $unconfigured = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    unlink(sprintf('%s/phpstan.neon', $unconfigured));

    expect(phpstanIn($failing)->configuration(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan could not say the configuration it runs with (exit 1: ).'))
        ->and(phpstanIn($garbled)->configuration(Withheld::standard()))
        ->toEqual(CannotJudge::because('The analyser\'s resolved configuration is no object: null'))
        ->and(phpstanIn($unconfigured)->configuration(Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan has no config to read: add a phpstan.neon, or name one in staticCheck.config.'));
});

it('refuses a config option that is no path', function (): void {
    expect(PhpStan::fromOptions(Configs::options('{"config": 5}'), Scratch::directory(), new LocalProcesses(new SystemClock())))
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
    Scratch::write($project, 'vendor/bin/answer.json', sprintf('{"totals": {}, "files": {"%s/src/Money.php": {"messages": [{"message": "Method add() should return int but returns string.", "identifier": "return.type"}]}}, "errors": []}', $project));
    Scratch::write($project, 'vendor/bin/answer.exit', '1');
    Scratch::write($project, 'src/Money.php', '<?php');
    Scratch::write($project, 'vendor/bin/params.json', sprintf('{"paths": ["%s/src"]}', $project));
    $phpstan = phpstanIn($project);
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
});

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
    $phpstan = phpstanIn($project);
    $phpstan->findings(Paths::none(), Withheld::standard());

    expect($phpstan->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))))->toEqual(Findings::of(
        Finding::error(Path::of('src/Money.php'), 'return.type', 'Method add() should return int but returns string.'),
        Finding::error(Path::of('src/Wallet.php'), 'return.type', 'Method total() should return int but returns string.'),
    ));
});

it('leaves a mutant out of its scope where its original is outside the paths it analyses, or excluded', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'vendor/bin/params.json', sprintf(
        '{"paths": ["%1$s/src"], "excludePaths": {"analyseAndScan": ["%1$s/src/Legacy"], "analyse": ["%1$s/src/*.gen.php"]}}',
        $project,
    ));
    Scratch::write($project, 'vendor/bin/answer.json', '{"totals": {}, "files": {}, "errors": []}');
    Scratch::write($project, 'src/Money.php', '<?php');
    $phpstan = phpstanIn($project);
    $phpstan->findings(Paths::none(), Withheld::standard());
    $checked = static fn(string $file): Findings|OutOfScope|CannotJudge => $phpstan->check(MutantCheck::of(Path::of($file), Path::of('/tmp/mutant.php')));

    expect($checked('lib/Other.php'))->toEqual(OutOfScope::of(Path::of('lib/Other.php')))
        ->and($checked('src/Legacy/Old.php'))->toEqual(OutOfScope::of(Path::of('src/Legacy/Old.php')))
        ->and($checked('src/Money.gen.php'))->toEqual(OutOfScope::of(Path::of('src/Money.gen.php')))
        ->and($checked('src/Money.php'))->toEqual(Findings::none());
});

it('reads a file as PHPStan walks to it, through a linked directory or a root that is a link, and not as it really is', function (): void {
    $real = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($real, 'shared/B.php', '<?php');
    Scratch::write($real, 'vendor/bin/answer.json', '{"totals": {}, "files": {}, "errors": []}');
    mkdir(sprintf('%s/src', $real));
    symlink('../shared', sprintf('%s/src/Linked', $real));
    $linked = sprintf('%s-link', $real);
    symlink($real, $linked);
    Scratch::write($real, 'vendor/bin/params.json', sprintf('{"paths": ["%s/src"]}', $linked));
    $phpstan = phpstanIn($linked);
    $phpstan->findings(Paths::none(), Withheld::standard());
    $checked = $phpstan->check(MutantCheck::of(Path::of('src/Linked/B.php'), Path::of('/tmp/mutant.php')));
    $shared = $phpstan->check(MutantCheck::of(Path::of('shared/B.php'), Path::of('/tmp/mutant.php')));
    unlink($linked);

    expect($checked)->toEqual(Findings::none())
        ->and($shared)->toEqual(OutOfScope::of(Path::of('shared/B.php')));
});

it('never reads a scope an earlier warm-up kept once a later one cannot say its own', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, '.mutation-gate/phpstan/scope.json', sprintf('{"paths": ["%s/src"]}', $project));
    Scratch::write($project, 'vendor/bin/params.exit', '1');
    $phpstan = phpstanIn($project);

    expect($phpstan->findings(Paths::none(), Withheld::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and(is_file(sprintf('%s/.mutation-gate/phpstan/scope.json', $project)))->toBeFalse()
        ->and($phpstan->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))))
        ->toEqual(CannotJudge::because('PHPStan\'s run over the originals has not said which files it analyses.'));
});

it('cannot warm up where it cannot remove the scope an earlier warm-up kept', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    $phpstan = phpstanIn($project);
    $phpstan->findings(Paths::none(), Withheld::standard());
    $directory = sprintf('%s/.mutation-gate/phpstan', $project);
    chmod($directory, 0o555);
    $warmed = $phpstan->findings(Paths::none(), Withheld::standard());
    chmod($directory, 0o755);

    expect($warmed)->toEqual(CannotJudge::because(
        sprintf('The gate cannot write PHPStan\'s config for its checks to %s/scope.json.', $directory),
    ));
});

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

    expect(phpstanIn($unwarmed)->check($check))
        ->toEqual(CannotJudge::because('PHPStan\'s run over the originals has not said which files it analyses.'))
        ->and(phpstanIn($failing)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan could not say which files it analyses (exit 1: ).'))
        ->and(phpstanIn($garbled)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because('PHPStan did not say which files it analyses: the parameters.paths is not a list.'))
        ->and(phpstanIn($unkept)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('The gate cannot write PHPStan\'s config for its checks to %s/.mutation-gate/phpstan/scope.json.', $unkept)));
});

it('cannot judge where it cannot write its own config', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, '.mutation-gate/phpstan', 'a file where the directory belongs');

    expect(phpstanIn($project)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('The gate cannot write PHPStan\'s config for its checks to %s/.mutation-gate/phpstan/check.neon.', $project)));
});

it('cannot judge in a root that is not there, where its process never starts', function (): void {
    $gone = PhpStan::fromOptions(Configs::options('{}'), sprintf('%s/gone', Scratch::directory()), new LocalProcesses(new SystemClock()));

    expect($gone instanceof PhpStan ? $gone->identity(Withheld::standard()) : $gone)
        ->toEqual(CannotJudge::because('PHPStan has no config to read: add a phpstan.neon, or name one in staticCheck.config.'));
});

it('reads no dependents a check lists, finding them itself', function (): void {
    expect(phpstanIn(FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16'))->readsDependents())->toBeFalse();
});

it('cannot judge a check that takes longer than its limit, stopping it there', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'vendor/bin/answer.json', '{"totals": {}, "files": {}, "errors": []}');
    Scratch::write($project, 'src/Money.php', '<?php');
    Scratch::write($project, 'vendor/bin/params.json', sprintf('{"paths": ["%s/src"]}', $project));
    $phpstan = phpstanIn($project);
    $phpstan->findings(Paths::none(), Withheld::standard());
    Scratch::write($project, 'vendor/bin/answer.sleep', '30');
    $started = microtime(as_float: true);

    $checked = $phpstan->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))->within(Seconds::of(1.0)));

    expect($checked)->toEqual(CannotJudge::because('PHPStan did not finish a check in 1s.'))
        ->and(microtime(as_float: true) - $started)->toBeLessThan(20.0);
});
