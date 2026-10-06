<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Outcome;
use NightWorksIO\MutationGate\Adapter\PhpUnit\PhpUnit;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\FirstMutant;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project whose Money's sum a test of tests/MoneyTest.php covers, with PHPUnit installed. */
function phpUnitRunnerProject(): Project
{
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a + \$b;\n}\n");
    Scratch::write($root, 'tests/MoneyTest.php', "<?php\n\nnamespace Tests;\n\nfinal class MoneyTest {}\n");
    Scratch::write($root, 'vendor/bin/phpunit', '<?php');
    Scratch::write($root, 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'phpunit/phpunit', 'version' => '13.3.4', 'source' => ['reference' => 'p']],
        ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.5', 'source' => ['reference' => 'c']],
    ]]));

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
}

/**
 * A PHPUnit that describes the PHP it runs on, lists two groups, runs the suite under coverage, in which a test
 * covers Money's sum, passes a run of no test, and fails the test in each mutant's run.
 */
function phpUnitAnswering(Project $project): PhpUnitShellFake
{
    return new PhpUnitShellFake(static function (Command $command) use ($project): Ran {
        $arguments = implode(' ', $command->arguments());
        $mapped = array_values(array_filter($command->arguments(), static fn(string $a): bool => str_starts_with($a, '--coverage-php=')));

        if ($mapped !== []) {
            $file = substr($mapped[0], strlen('--coverage-php='));
            if ($file === '') {
                return Ran::finished(succeeded: false, output: 'No map named');
            }

            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), recursive: true);
            }
            CoverageMaps::write($file, sprintf('%s/', $project->root()), ['src/Money.php' => [5 => [0]]], ['Tests\MoneyTest::testAdds'], ['Tests\MoneyTest::testAdds' => 0.5]);

            return Ran::finished(succeeded: true, output: 'covered');
        }

        return match (true) {
            str_contains($arguments, '--list-groups') => Ran::finished(succeeded: true, output: "Available test groups:\n - default (1 test)\n - holds:src/Money.php (1 test)\n"),
            str_contains($arguments, '(?!)') => Ran::finished(succeeded: true, output: 'No tests executed!')->took(Seconds::of(0.3)),
            in_array('-r', $command->arguments(), strict: true) => Ran::finished(succeeded: true, output: Described::output()),
            default => (static function () use ($command): Ran {
                file_put_contents($command->environment()[Variable::Results->value], Outcome::Failed->line('Tests\MoneyTest::testAdds'));
                file_put_contents($command->environment()[Variable::Guard->value], "served\n");

                return Ran::finished(succeeded: false, output: 'FAILURES!')->took(Seconds::of(0.2));
            })(),
        };
    });
}

function phpUnitRunner(Project $project, PhpUnitShellFake $shell): PhpUnit
{
    return new PhpUnit($project, $shell, Engine::with(new PlusToMinus()), Seconds::of(7.0), new CapDirectory());
}

$request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

it('is built from the options the flows write, or refuses those not in their shape', function (): void {
    $built = PhpUnit::fromOptions(Configs::options('{"timeout": 30}'), Path::of('vendor'), new CapDirectory(), new LocalProcesses(new SystemClock()));
    $refused = PhpUnit::fromOptions(Configs::options('{"timeout": "long"}'), Path::of('vendor'), new CapDirectory(), new LocalProcesses(new SystemClock()));

    expect($built)->toBeInstanceOf(PhpUnit::class)
        ->and($built instanceof PhpUnit ? $built->mutate(MutationRequest::of(Paths::none(), WholeSuite::tests())) : $built)
        ->toEqual(CannotJudge::because('The phpunit runner makes its mutants with the default mutator set, and no extension registers one.'))
        ->and($refused)->toBeInstanceOf(Invalid::class);
});

it('names PHPUnit and php-code-coverage, and the PHP a mutant runs on, with opcache off', function (): void {
    $project = phpUnitRunnerProject();
    $shell = phpUnitAnswering($project);
    $withheld = Withheld::of('DEPLOY_*');

    expect(phpUnitRunner($project, $shell)->identity($withheld))->toEqual(Identity::of('phpunit', Versions::of(
        Version::of('phpunit/phpunit', '13.3.4', 'p'),
        Version::of('phpunit/php-code-coverage', '14.3.5', 'c'),
    ), Described::platform()->digest()))
        ->and($shell->commands())->toEqual([Command::php('-d', 'opcache.enable_cli=0', ...Platform::describing())->withholding($withheld)]);
});

it('cannot name a PHPUnit it cannot drive, or a PHP that does not describe itself', function (): void {
    $old = phpUnitRunnerProject();
    Scratch::write($old->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'phpunit/phpunit', 'version' => '13.1.0'],
        ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.5'],
    ]]));
    $silent = phpUnitRunnerProject();
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: false, output: 'Segmentation fault'));

    expect(phpUnitRunner($old, $shell)->identity(Withheld::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and($shell->commands())->toBe([])
        ->and(phpUnitRunner($silent, $shell)->identity(Withheld::standard()))->toEqual(Platform::ofRunner('Segmentation fault'));
});

it('behaves as a standard runner: holds as groups, a limit it can raise, the map the plan handed on, a mutant per core at once', function (): void {
    expect(phpUnitRunner(phpUnitRunnerProject(), PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: '')))->behaviour())
        ->toEqual(RunnerBehaviour::standard()->runningPerCore());
});

it('lists the groups PHPUnit lists for the suite', function (): void {
    $project = phpUnitRunnerProject();
    $shell = phpUnitAnswering($project);

    expect(phpUnitRunner($project, $shell)->groups(Withheld::of('SECRET')))
        ->toEqual(Groups::of(Group::named('default'), Group::named('holds:src/Money.php')))
        ->and($shell->commands()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')));
});

it('measures coverage, reads a map handed on, and names the test files that judge a covered file and none for another', function (): void {
    $project = phpUnitRunnerProject();
    $runner = phpUnitRunner($project, phpUnitAnswering($project));
    $map = $runner->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $missing = $runner->coverage(CoverageRead::from(Path::of('.mutation-gate/nowhere')));

    expect($map instanceof CoverageMap ? $runner->judges(Path::of('src/Money.php'), $map) : $map)
        ->toEqual(Paths::of(Path::of('tests/MoneyTest.php')))
        ->and($map instanceof CoverageMap ? $runner->judges(Path::of('src/Tax.php'), $map) : $map)->toEqual(Paths::none())
        ->and($missing)->toBeInstanceOf(CannotJudge::class);
});

it('names the tests of a map whose classes a test file declares, or that its gone file held', function (): void {
    $project = phpUnitRunnerProject();
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(5), TestId::of('Tests\MoneyTest::testAdds'))
        ->covered(Path::of('src/Money.php'), Line::of(5), TestId::of('Tests\GoneTest::testGoes'))
        ->covered(Path::of('src/Money.php'), Line::of(5), TestId::of('Tests\TaxTest::testTaxes'));
    $files = Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/GoneTest.php'));

    expect(phpUnitRunner($project, phpUnitAnswering($project))->testsIn($files, $map))->toEqual(TestIds::of(
        TestId::of('Tests\MoneyTest::testAdds'),
        TestId::of('Tests\GoneTest::testGoes'),
    ));
});

it('times a run of no test, started as a mutant\'s own run, its mutant the file unchanged', function (): void {
    $project = phpUnitRunnerProject();
    $shell = phpUnitAnswering($project);
    $startUp = phpUnitRunner($project, $shell)->startUp(Path::of('src/Money.php'), Withheld::of('SECRET'));
    $command = $shell->commands()[0];

    expect($startUp)->toEqual(Seconds::of(0.3))
        ->and($command->arguments()[4] ?? '')->toBe(sprintf('auto_prepend_file=%s/.mutation-gate/phpunit/override.php', $project->root()))
        ->and(is_file(sprintf('%s/.mutation-gate/phpunit/override.php', $project->root())))->toBeTrue()
        ->and((string) file_get_contents($command->environment()[Variable::Mutated->value]))
        ->toBe((string) file_get_contents($project->absolute(Path::of('src/Money.php'))));
});

it('cannot time a run of no test that fails, of a file that is gone, or without its override', function (string $broken): void {
    $project = phpUnitRunnerProject();
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: false, output: 'Could not read phpunit.xml'));
    $file = $broken === 'a file that is gone' ? 'src/Gone.php' : 'src/Money.php';

    if ($broken === 'no override') {
        Scratch::write($project->root(), '.mutation-gate/phpunit/override.php/inside', 'a directory where the script goes');
    }

    set_error_handler(static fn(): bool => true);
    $startUp = phpUnitRunner($project, $shell)->startUp(Path::of($file), Withheld::standard());
    restore_error_handler();

    expect($startUp)->toBeInstanceOf(CannotJudge::class)
        ->and($startUp instanceof CannotJudge ? $startUp->why() : '')->toBe(match ($broken) {
            'a run that fails' => "PHPUnit's run of no test, timing a mutant's start-up, failed. PHPUnit said:\nCould not read phpunit.xml",
            'a file that is gone' => 'The gate cannot read src/Gone.php, which a run of no test serves unchanged as its mutant.',
            default => sprintf('The gate cannot write %s/.mutation-gate/phpunit/override.php, which the PHPUnit it starts reads.', $project->root()),
        });
})->with(['a run that fails', 'a file that is gone', 'no override']);

it('mutates afresh each time, allowing each mutant its limit under the cap, and runs a mutant again on the map its run read under a raised one', function () use ($request): void {
    $project = phpUnitRunnerProject();
    $shell = phpUnitAnswering($project);
    $runner = phpUnitRunner($project, $shell);
    $first = $runner->mutate($request);
    $runner->mutate($request);
    $money = FirstMutant::of($first);
    $retried = $runner->retry($request, Mutants::of($money), Seconds::of(14.0));
    $coverageRuns = array_filter($shell->commands(), static fn(Command $command): bool => preg_grep('/^--coverage-php=/', $command->arguments()) !== []);
    $last = $shell->commands()[count($shell->commands()) - 1];

    expect($money->status())->toBe(MutantStatus::Killed)
        ->and($shell->commands()[1]->deadline())->toEqual(Seconds::of(7.0))
        ->and($retried instanceof Mutants ? array_map(static fn(Mutant $mutant): string => $mutant->id()->value(), [...$retried]) : [])
        ->toBe([$money->id()->value()])
        ->and($last->deadline())->toEqual(Seconds::of(7.5))
        ->and($coverageRuns)->toHaveCount(2);
});

it('reproduces a mutant on its own, on the map its run read, with what PHPUnit printed', function () use ($request): void {
    $project = phpUnitRunnerProject();
    $runner = phpUnitRunner($project, phpUnitAnswering($project));
    $first = $runner->mutate($request);
    $money = FirstMutant::of($first);
    $reproduced = $runner->reproduce(Reproducible::of($money), $request, Seconds::of(5.0));

    expect($reproduced instanceof Reproduction ? $reproduced->printed() : '')->toBe('FAILURES!')
        ->and($reproduced instanceof Reproduction && $reproduced->mutant() instanceof Mutant ? $reproduced->mutant()->id() : null)
        ->toEqual($money->id());
});

it('gives a mutant as an analyser checks it: its diff put onto the file as written, or cannot where the file is gone', function (): void {
    $project = phpUnitRunnerProject();
    $runner = phpUnitRunner($project, PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: '')));
    $mutant = static fn(string $file): Mutant => Mutant::of(
        MutantId::hash(Path::of($file), 'acme/PlusToMinus', "@@ @@\n-    return \$a + \$b;\n+    return \$a - \$b;", 0),
        '',
        Location::of(Path::of($file), Line::of(5), Line::of(5)),
        Mutation::of('acme/PlusToMinus', MutatorFamily::Arithmetic, "@@ @@\n-    return \$a + \$b;\n+    return \$a - \$b;"),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $checkable = $runner->checkable($mutant('src/Money.php'));

    expect($checkable instanceof Checkable ? $checkable->mutant() : $checkable)
        ->toEqual(Contents::of("<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a - \$b;\n}\n"))
        ->and($runner->checkable($mutant('src/Gone.php')))
        ->toEqual(CannotJudge::because('The gate cannot read src/Gone.php, the file the mutant was made from, to check it.'));
});

it('has no ignore marker of its own, and is defined by the PHPUnit config in the root', function (): void {
    $runner = phpUnitRunner(phpUnitRunnerProject(), PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: '')));

    expect($runner->markers(Paths::of(Path::of('src'))))->toEqual(Markers::none())
        ->and($runner->definitions())->toEqual(Paths::of(Path::of('phpunit.xml'), Path::of('phpunit.dist.xml'), Path::of('phpunit.xml.dist')));
});

it('names each test by the file that declares its class', function (): void {
    $runner = phpUnitRunner(phpUnitRunnerProject(), PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: '')));
    $adds = TestId::of('Tests\MoneyTest::testAdds');

    expect($runner->names(TestIds::of($adds), Withheld::standard())->nameOf($adds))
        ->toEqual(TestName::in(Path::of('tests/MoneyTest.php'), 'testAdds'));
});

it('answers as PHPUnit in a package where Composer installed it there, and cannot judge one where it did not', function (): void {
    $project = phpUnitRunnerProject();
    Scratch::write($project->root(), 'packages/billing/vendor/bin/phpunit', '<?php');
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $runner = phpUnitRunner($project, $shell);

    expect($runner->rootedAt(Path::of('packages/billing')))->toBeInstanceOf(PhpUnit::class)
        ->and($shell->directories())->toBe([sprintf('%s/packages/billing', $project->root())])
        ->and($runner->rootedAt(Path::of('packages/none')))->toEqual(CannotJudge::because(
            'packages/none holds no project the phpunit runner can run: PHPUnit is not installed in its vendor.',
        ));
});
