<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Invocation;
use NightWorksIO\MutationGate\Adapter\Native\MutantFiles;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Workplace;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\NativePhpUnit;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

$project = static function (): Project {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nreturn 1 + 1;\n");

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit);
};

/** The files of a run of no test in the project, its mutant Money unchanged. */
function invocationStartingUp(Project $project): MutantFiles
{
    $selection = NativePhpUnit::in($project)->selection(TestIds::none());
    $files = $selection instanceof Selection ? MutantFiles::startingUp($project, Path::of('src/Money.php'), $selection) : $selection;

    return $files instanceof MutantFiles ? $files : throw new LogicException('Money starts up.');
}

it('starts a run of no test with opcache off and the override, telling it its files, the framework\'s run after', function () use ($project): void {
    $at = $project();
    $files = invocationStartingUp($at);
    $withheld = Withheld::of('SECRET');
    $command = new Invocation($at, '/gate/override.php', NativePhpUnit::in($at))->startingUp($files, $withheld);
    $told = $command->environment();

    expect($command->arguments())->toBe([
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        '-d',
        'auto_prepend_file=/gate/override.php',
        ...NativePhpUnit::in($at)->startUp($files->selection()),
    ])
        ->and($told)->toBe([
            Variable::Results->value => $files->results(),
            Variable::Guard->value => $files->guard(),
            Variable::Mutant->value => sprintf('%s/src/Money.php', $at->root()),
            Variable::Mutated->value => $files->mutated(),
        ])
        ->and((string) file_get_contents($told[Variable::Mutated->value]))->toBe("<?php\n\nreturn 1 + 1;\n")
        ->and($command->withheld())->toEqual(Withheld::standard()->and($withheld))
        ->and($command->deadline())->toEqual(Unlimited::time());
});

it('starts a mutant\'s run as the framework runs it for the request, withholding what it withholds, within the limit', function () use ($project): void {
    $at = $project();
    $files = invocationStartingUp($at);
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->withholding(Withheld::of('DEPLOY_*'));
    $command = new Invocation($at, '/gate/override.php', NativePhpUnit::in($at))->of($files, $request, Seconds::of(7.0));

    expect(array_slice($command->arguments(), 5))->toBe(NativePhpUnit::in($at)->mutant($files->selection(), $request))
        ->and($command->withheld())->toEqual(Withheld::standard()->and(Withheld::of('DEPLOY_*')))
        ->and($command->deadline())->toEqual(Seconds::of(7.0));
});

it('describes the PHP a mutant runs on, with opcache off as the mutant\'s run has it', function () use ($project): void {
    $at = $project();
    $command = new Invocation($at, '/gate/override.php', NativePhpUnit::in($at))->describing(Withheld::of('SECRET'));

    expect($command->arguments())->toBe([PHP_BINARY, '-d', 'opcache.enable_cli=0', ...Platform::describing()])
        ->and($command->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')));
});

it('runs the coverage run with pcov collecting from the whole project but its vendor directory, the framework\'s run after', function () use ($project): void {
    $at = $project();
    $run = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $coverage = new Invocation($at, '/gate/override.php', NativePhpUnit::in($at))->coverage($run, '/map.php');

    expect($coverage->arguments())->toBe([
        PHP_BINARY,
        '-d',
        sprintf('pcov.directory=%s', $at->root()),
        '-d',
        sprintf('pcov.exclude=~^%s/vendor/~', preg_quote($at->root(), '~')),
        ...NativePhpUnit::in($at)->coverage($run, '/map.php'),
    ])
        ->and($coverage->withheld())->toEqual(Withheld::standard());
});

it('starts a warm worker as a mutant\'s own run starts PHP, on the worker\'s script, the autoloader and its place, writing its output to its files', function () use ($project): void {
    $at = $project();
    $workplace = Workplace::at(sprintf('%s/warm', $at->root()));
    $command = new Invocation($at, '/gate/override.php', NativePhpUnit::in($at))->worker(OwnScript::Worker, $workplace, 2, Withheld::of('SECRET'));

    expect($command->arguments())->toBe([
        '/bin/sh',
        '-c',
        'out=$1 err=$2; shift 2; exec "$@" >"$out" 2>"$err"',
        'sh',
        $workplace->out(2),
        $workplace->err(2),
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        '-d',
        'auto_prepend_file=/gate/override.php',
        Tree::at('bin/mutation-gate-worker'),
        sprintf('%s/vendor/autoload.php', $at->root()),
        $workplace->directory(),
        '2',
    ])
        ->and($command->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')))
        ->and($command->environment())->toBe([]);
});
