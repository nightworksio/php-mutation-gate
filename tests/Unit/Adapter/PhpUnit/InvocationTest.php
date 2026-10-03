<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Extension;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$project = static function (): Project {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nreturn 1 + 1;\n");

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
};

it('starts a run of no test as a mutant\'s own run, its mutant the file unchanged, passing with no test run', function () use ($project): void {
    $at = $project();
    $files = MutantFiles::startingUp($at, Path::of('src/Money.php'));
    $withheld = Withheld::of('SECRET');
    $command = $files instanceof MutantFiles ? new Invocation($at, '/gate/override.php')->startingUp($files, $withheld) : null;
    $arguments = $command?->arguments() ?? [];
    $told = $command?->environment() ?? [];

    expect(array_slice($arguments, 0, 8))->toBe([
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        '-d',
        'auto_prepend_file=/gate/override.php',
        sprintf('%s/vendor/bin/phpunit', $at->root()),
        '--extension',
        Extension::class,
    ])
        ->and($arguments[8] ?? '')->toBe(sprintf('--test-id-filter-file=%s/.mutation-gate/phpunit/start-up/ids.txt', $at->root()))
        ->and(array_slice($arguments, 9))->toBe([
            '--stop-on-error',
            '--stop-on-failure',
            '--no-coverage',
            '--no-logging',
            '--do-not-record-test-run-history',
            '--no-progress',
            '--filter',
            '(?!)',
            '--do-not-fail-on-empty-test-suite',
        ])
        ->and((string) file_get_contents(sprintf('%s/.mutation-gate/phpunit/start-up/ids.txt', $at->root())))->toBe("\n")
        ->and($told[Variable::Mutant->value] ?? '')->toBe(sprintf('%s/src/Money.php', $at->root()))
        ->and((string) file_get_contents($told[Variable::Mutated->value] ?? ''))->toBe("<?php\n\nreturn 1 + 1;\n")
        ->and($command?->withheld())->toEqual(Withheld::standard()->and($withheld))
        ->and($command?->deadline())->toEqual(Unlimited::time());
});

it('lists the suite\'s groups without colour, and runs no test', function () use ($project): void {
    $at = $project();
    $command = new Invocation($at, '/gate/override.php')->listingGroups(Withheld::of('SECRET'));

    expect($command->arguments())->toBe([PHP_BINARY, sprintf('%s/vendor/bin/phpunit', $at->root()), '--list-groups', '--colors=never'])
        ->and($command->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')))
        ->and($command->environment())->toBe([]);
});

it('describes the PHP a mutant runs on, with opcache off as the mutant\'s run has it', function () use ($project): void {
    $command = new Invocation($project(), '/gate/override.php')->describing(Withheld::of('SECRET'));

    expect($command->arguments())->toBe([PHP_BINARY, '-d', 'opcache.enable_cli=0', ...Platform::describing()])
        ->and($command->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')));
});

it('leaves the test run history as it was with the option the installed PHPUnit names, never one it deprecates', function (string $version, string $leaves, string $deprecated): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nreturn 1 + 1;\n");
    Scratch::write($root, 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'phpunit/phpunit', 'version' => $version, 'source' => ['reference' => 'abc']],
    ]]));
    $at = Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
    $files = MutantFiles::startingUp($at, Path::of('src/Money.php'));
    $invocation = new Invocation($at, '/gate/override.php');
    $mutant = $files instanceof MutantFiles ? $invocation->startingUp($files, Withheld::of())->arguments() : [];
    $coverage = $invocation->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')), '/map.php')->arguments();

    expect($mutant)->toContain($leaves)
        ->and($coverage)->toContain($leaves)
        ->and(in_array($deprecated, [...$mutant, ...$coverage], strict: true))->toBeFalse();
})->with([
    'PHPUnit 13.3 on, which deprecates --do-not-cache-result' => ['13.3.0', '--do-not-record-test-run-history', '--do-not-cache-result'],
    'PHPUnit before 13.3, which has no --do-not-record-test-run-history' => ['13.2.0', '--do-not-cache-result', '--do-not-record-test-run-history'],
]);
