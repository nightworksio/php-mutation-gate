<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Extension;
use NightWorksIO\MutationGate\Adapter\PhpUnit\PhpUnitFramework;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Coverage\Fresh;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\NativePhpUnit;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\TestLists;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/** PHPUnit in a scratch project, at this version where one is given, its tests in tests/. */
function phpUnitFramework(string $version = ''): PhpUnitFramework
{
    $root = Scratch::directory();

    if ($version !== '') {
        Scratch::write($root, 'vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'phpunit/phpunit', 'version' => $version, 'source' => ['reference' => 'p']],
            ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.5', 'source' => ['reference' => 'c']],
        ]]));
    }

    return NativePhpUnit::in(Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit));
}

$request = static fn(): MutationRequest => MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

it('is PHPUnit, run as the phpunit runner, defined by the PHPUnit config in the root', function (): void {
    $framework = phpUnitFramework();

    expect($framework->program())->toBe(Program::PhpUnit)
        ->and($framework->runner())->toBe(BuiltinRunner::PhpUnit)
        ->and($framework->definitions())->toEqual(Paths::of(Path::of('phpunit.xml'), Path::of('phpunit.dist.xml'), Path::of('phpunit.xml.dist')))
        ->and($framework->tests())->toBeInstanceOf(TestFiles::class)
        ->and($framework->testStyle())->toBe(AssertionStyle::PhpUnit);
});

it('names the packages it drives at a version the runner supports, and cannot judge an older PHPUnit', function (): void {
    expect(phpUnitFramework('13.3.4')->versions())->toEqual(Versions::of(
        Version::of('phpunit/phpunit', '13.3.4', 'p'),
        Version::of('phpunit/php-code-coverage', '14.3.5', 'c'),
    ))
        ->and(phpUnitFramework('13.1.0')->versions())->toBeInstanceOf(CannotJudge::class);
});

it('runs the script and the config the project installed, and boots its warm workers on the gate\'s own script', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'vendor/bin/phpunit', '<?php');
    Scratch::write($root, 'phpunit.xml', '<phpunit><php><ini name="display_errors" value="0"/></php></phpunit>');
    $framework = NativePhpUnit::in(Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit));
    $real = (string) realpath($root);

    expect($framework->isInstalled())->toBeTrue()
        ->and($framework->script())->toBe(sprintf('%s/vendor/bin/phpunit', $real))
        ->and($framework->config())->toBe(sprintf('%s/phpunit.xml', $real))
        ->and($framework->errorDisplay())->toBe(ErrorDisplay::Nowhere)
        ->and($framework->workerScript())->toBe(Tree::at('bin/mutation-gate-worker'))
        ->and(is_file(Tree::at('bin/mutation-gate-worker')))->toBeTrue()
        ->and(phpUnitFramework()->config())->toEqual(NotGiven::value());
});

it('is PHPUnit in a package where Composer installed it there, and not one where it did not', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'packages/billing/vendor/bin/phpunit', '<?php');
    $framework = NativePhpUnit::in(Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit));

    $layout = static fn(string $package): TestFiles => new TestFiles(
        Project::at(sprintf('%s/%s', $root, $package), Paths::none(), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit),
    );

    expect($framework->in(Path::of('packages/billing'), $layout('packages/billing'))->isInstalled())->toBeTrue()
        ->and($framework->in(Path::of('packages/none'), $layout('packages/none'))->isInstalled())->toBeFalse()
        ->and($framework->in(Path::of('packages/billing'), $layout('packages/billing'))->tests())->toEqual($layout('packages/billing'))
        ->and($framework->isInstalled())->toBeFalse();
});

it('selects the covering tests by their ids, exactly, where PHPUnit reads each back', function (): void {
    $selection = phpUnitFramework()->selection(TestIds::of(TestId::of('Tests\MoneyTest::testAdds'), TestId::of('Tests\MoneyTest::testRounds')));

    expect($selection instanceof Selection ? [$selection->file(), $selection->lines(), $selection->isExact(), $selection->option('/g/ids.txt')] : $selection)
        ->toBe(['ids.txt', ['Tests\MoneyTest::testAdds', 'Tests\MoneyTest::testRounds'], true, '--test-id-filter-file=/g/ids.txt']);
});

it('selects the covering tests by the files that declare their classes where an id has a line break, or says no file places them', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'tests/MoneyTest.php', "<?php\nnamespace Tests;\nfinal class MoneyTest {}");
    $framework = NativePhpUnit::in(Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit));
    $placed = $framework->selection(TestIds::of(TestId::of("Tests\\MoneyTest::testAdds#a\nb")));
    $unplaced = $framework->selection(TestIds::of(TestId::of("Tests\\GoneTest::testGoes#a\nb")));

    expect($placed instanceof Selection ? [$placed->file(), $placed->lines(), $placed->isExact()] : $placed)
        ->toBe(['files.txt', [sprintf('%s/tests/MoneyTest.php', $root)], false])
        ->and($unplaced)->toEqual(Reason::that('A test that covers it has a line break in its name, and no test file found holds every test that covers it.'));
});

it('starts a run of no test as a mutant\'s own run, through the extension, passing with no test run', function (): void {
    $framework = phpUnitFramework('13.3.4');

    expect($framework->startUp('--test-id-filter-file=/g/ids.txt'))->toBe([
        $framework->script(),
        '--extension',
        Extension::class,
        '--test-id-filter-file=/g/ids.txt',
        '--stop-on-error',
        '--stop-on-failure',
        '--no-coverage',
        '--no-logging',
        '--do-not-record-test-run-history',
        sprintf('--cache-directory=%s/.mutation-gate/phpunit-cache', dirname($framework->script(), 3)),
        '--no-progress',
        '--filter',
        '(?!)',
        '--do-not-fail-on-empty-test-suite',
    ]);
});

it('runs a mutant\'s tests through the extension, stopped at the first that fails unless every killer is asked for, kept to the unit\'s tests', function () use ($request): void {
    $framework = phpUnitFramework('13.3.4');
    $grouped = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));
    $full = $request()->searching(KillSearch::of(Ordering::runner(), MatrixKind::Full));

    expect($framework->mutant('--test-id-filter-file=/g/ids.txt', $request()))->toBe([
        $framework->script(),
        '--extension',
        Extension::class,
        '--test-id-filter-file=/g/ids.txt',
        '--stop-on-error',
        '--stop-on-failure',
        '--no-coverage',
        '--no-logging',
        '--do-not-record-test-run-history',
        sprintf('--cache-directory=%s/.mutation-gate/phpunit-cache', dirname($framework->script(), 3)),
        '--no-progress',
    ])
        ->and(array_slice($framework->mutant('--sel', $grouped), -2))->toBe(['--group', 'holds:src/Money.php'])
        ->and($framework->mutant('--sel', $full))->not->toContain('--stop-on-error')
        ->and($framework->mutant('--sel', $full))->not->toContain('--stop-on-failure');
});

it('leaves the test run history as it was with the option the installed PHPUnit names, never one it deprecates', function (string $version, string $leaves, string $deprecated) use ($request): void {
    $framework = phpUnitFramework($version);
    $mutant = $framework->mutant('--sel', $request());
    $coverage = $framework->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')), '/map.php');

    expect($mutant)->toContain($leaves)
        ->and($coverage)->toContain($leaves)
        ->and(in_array($deprecated, [...$mutant, ...$coverage], strict: true))->toBeFalse();
})->with([
    'PHPUnit 13.3 on, which deprecates --do-not-cache-result' => ['13.3.0', '--do-not-record-test-run-history', '--do-not-cache-result'],
    'PHPUnit before 13.3, which has no --do-not-record-test-run-history' => ['13.2.0', '--do-not-cache-result', '--do-not-record-test-run-history'],
]);

it('runs the coverage run\'s tests with their map written as --coverage-php writes it, by a filter, a group or their files', function (): void {
    $framework = phpUnitFramework('13.3.4');
    $into = Path::of('.mutation-gate/coverage');

    expect($framework->coverage(CoverageRun::of(WholeSuite::tests(), $into), '/map.php'))->toBe([
        $framework->script(),
        '--coverage-php=/map.php',
        '--no-logging',
        '--do-not-record-test-run-history',
        sprintf('--cache-directory=%s/.mutation-gate/phpunit-cache', dirname($framework->script(), 3)),
        '--no-progress',
    ])
        ->and(array_slice($framework->coverage(CoverageRun::of(Filter::matching('MoneyTest'), $into), '/map.php'), -2))->toBe(['--filter', 'MoneyTest'])
        ->and(array_slice($framework->coverage(CoverageRun::of(TestPaths::of(Paths::of(Path::of('tests/MoneyTest.php'))), $into), '/map.php'), -1))
        ->toBe(['tests/MoneyTest.php']);
});

it('keeps the coverage run and each mutant\'s run to one suite where the run names one', function () use ($request): void {
    $framework = phpUnitFramework('13.3.4');
    $suited = $request()->narrowedTo($request()->files(), Narrowing::none()->toSuite(SuiteName::of('unit')));
    $run = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));

    expect($framework->mutant('--sel', $suited))->toContain('--testsuite=unit')
        ->and($framework->mutant('--sel', $request()))->not->toContain('--testsuite=unit')
        ->and(array_slice($framework->coverage($run->amongSuites(Suites::named(SuiteName::of('unit'))), '/map.php'), -1))->toBe(['--testsuite=unit'])
        ->and($framework->coverage($run, '/map.php'))->not->toContain('--testsuite=unit');
});

it('runs the holding suites too in a mutant\'s run its unit\'s holding tests judge, or the whole suite judges through a handed map, and never otherwise', function (): void {
    $framework = phpUnitFramework('13.3.4');
    $suites = Narrowing::none()->amongSuites(JudgingSuites::holding(Suites::listed('Unit'), Suites::listed('Process')));
    $mutant = static function (WholeSuite|Filter $judgedBy, Handed|Fresh $coverage) use ($framework, $suites): array {
        $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $judgedBy);
        $request = $coverage instanceof Handed ? $request->reusingCoverage($coverage) : $request;

        return $framework->mutant('--sel', $request->narrowedTo($request->files(), $suites));
    };
    $handed = Handed::maps(Path::of('shard'), Path::of('whole'));

    expect($mutant(WholeSuite::tests(), Fresh::coverage()))->toContain('--testsuite=Unit')
        ->and($mutant(WholeSuite::tests(), $handed))->toContain('--testsuite=Unit,Process')
        ->and($mutant(Filter::matching('MoneyTest'), Fresh::coverage()))->toContain('--testsuite=Unit,Process');
});

it('lists the tests of some suites into a file without colour, runs no test, and reads the list back', function (): void {
    $framework = phpUnitFramework('13.3.4');
    $file = sprintf('%s/tests.xml', Scratch::directory());
    $shell = new PhpUnitShellFake(static fn(Command $command): Ran => TestLists::wrote($command->arguments(), TestLists::of(['Tests\MoneyTest::testAdds'], ['default' => ['Tests\MoneyTest::testAdds']]))
        ? Ran::finished(succeeded: true, output: 'Wrote list')
        : Ran::finished(succeeded: false, output: 'broken'));
    $listing = $framework->listing($shell, Withheld::of('SECRET'), Suites::listed('Unit'), $file);

    expect($shell->commands()[0]->arguments())->toBe([PHP_BINARY, $framework->script(), sprintf('--list-tests-xml=%s', $file), '--colors=never', sprintf('--cache-directory=%s/.mutation-gate/phpunit-cache', dirname($framework->script(), 3)), '--testsuite=Unit'])
        ->and($shell->commands()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')))
        ->and($listing instanceof TestListing ? $listing->tests() : $listing)->toEqual(TestIds::of(TestId::of('Tests\MoneyTest::testAdds')));
});
