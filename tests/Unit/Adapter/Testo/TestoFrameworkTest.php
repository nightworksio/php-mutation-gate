<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\TestFiles;
use NightWorksIO\MutationGate\Adapter\Testo\Installation;
use NightWorksIO\MutationGate\Adapter\Testo\TestoFramework;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\CoverageReport;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\NativeTesto;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/** The project of a scratch root, its tests in tests/. */
function testoProject(string $root): Project
{
    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::Testo);
}

/** Testo in a scratch project, at this version where one is given. */
function testoFramework(string $version = ''): TestoFramework
{
    $root = Scratch::directory();

    if ($version !== '') {
        Scratch::write($root, 'vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'testo/testo', 'version' => $version, 'source' => ['reference' => 't']],
            ['name' => 'testo/codecov', 'version' => '0.2.5', 'source' => ['reference' => 'c']],
        ]]));
    }

    return NativeTesto::in(testoProject($root));
}

it('is Testo, run as the testo runner, defined by the testo.php in the root, and measured with its XML coverage', function (): void {
    $framework = testoFramework();

    expect($framework->program())->toBe(Program::Testo)
        ->and($framework->runner())->toBe(BuiltinRunner::Testo)
        ->and($framework->definitions())->toEqual(Paths::of(Path::of('testo.php')))
        ->and($framework->report())->toBe(CoverageReport::Xml)
        ->and($framework->tests())->toBeInstanceOf(TestFiles::class);
});

it('names the packages it drives where Testo is of the series the runner drives, and cannot judge another', function (): void {
    expect(testoFramework('0.10.56')->versions())->toEqual(Versions::of(
        Version::of('testo/testo', '0.10.56', 't'),
        Version::of('testo/codecov', '0.2.5', 'c'),
    ))
        ->and(testoFramework('0.11.0')->versions())->toBeInstanceOf(CannotJudge::class);
});

it('runs the gate\'s launcher, which reads no config of PHP\'s settings, boots no warm worker and writes no stub', function (): void {
    $framework = testoFramework();

    expect($framework->script())->toBe(Tree::at('bin/mutation-gate-testo'))
        ->and(is_file(Tree::at('bin/mutation-gate-testo')))->toBeTrue()
        ->and(is_file(Tree::at('bin/mutation-gate-testo-config.php')))->toBeTrue()
        ->and($framework->errorDisplay())->toEqual(NotGiven::value())
        ->and($framework->config())->toEqual(NotGiven::value())
        ->and($framework->workerScript())->toEqual(NotGiven::value())
        ->and($framework->endedHidden('PHP Fatal error: Allowed memory size exhausted', configuredToHide: true))->toBeFalse()
        ->and($framework->testStyle())->toEqual(NotGiven::value());
});

it('is Testo where Composer installed its script, and in a package where Composer installed it there', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'packages/billing/vendor/bin/testo', '<?php');
    $framework = NativeTesto::in(testoProject($root));
    $billing = new TestFiles(testoProject(sprintf('%s/packages/billing', $root)));

    expect($framework->isInstalled())->toBeFalse()
        ->and($framework->in(Path::of('packages/billing'), $billing)->isInstalled())->toBeTrue()
        ->and($framework->in(Path::of('packages/billing'), $billing)->tests())->toBe($billing)
        ->and($framework->in(Path::of('packages/none'), $billing)->isInstalled())->toBeFalse();
});

it('selects the covering tests by their addresses, exactly, from a file the launcher reads', function (): void {
    $selection = testoFramework()->selection(TestIds::of(TestId::of('Tests\MoneyTest::adds'), TestId::of('Tests\MoneyTest::rounds')));

    expect([$selection->file(), $selection->lines(), $selection->isExact(), $selection->option('/g/tests.txt')])
        ->toBe(['tests.txt', ['Tests\MoneyTest::adds', 'Tests\MoneyTest::rounds'], true, '--mutation-gate-tests=/g/tests.txt']);
});

it('starts its launcher with the project\'s autoloader for a mutant, a start-up and a coverage run', function (): void {
    $root = (string) realpath(Scratch::directory());
    $framework = NativeTesto::in(testoProject($root));
    $launched = [Tree::at('bin/mutation-gate-testo'), sprintf('%s/vendor/autoload.php', $root)];
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));

    expect(array_slice($framework->mutant('--sel', $request), 0, 4))->toBe([...$launched, '--sel', '--mutation-gate-group=holds:src/Money.php'])
        ->and(array_slice($framework->startUp('--sel'), 0, 3))->toBe([...$launched, '--sel'])
        ->and(array_slice($framework->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')), '/c/coverage-xml'), 0, 3))
        ->toBe([...$launched, '--mutation-gate-times=/c/times.txt']);
});

it('lists every test and its groups as the launcher wrote them, through PHP, withholding what the run withholds', function (): void {
    $root = (string) realpath(Scratch::directory());
    $framework = NativeTesto::in(testoProject($root));
    $file = sprintf('%s/listing.json', Scratch::directory());
    $shell = new PhpUnitShellFake(static function () use ($file): Ran {
        file_put_contents($file, (string) json_encode(['listed' => ['A::a', 'B::b'], 'groups' => ['holds:src/A.php' => ['A::a']]]));

        return Ran::finished(succeeded: true, output: '2 skipped');
    });
    $listing = $framework->listing($shell, Withheld::of('SECRET'), Suites::listed('unit'), $file);

    expect($shell->commands()[0]->arguments())->toBe([
        PHP_BINARY,
        $framework->script(),
        sprintf('%s/vendor/autoload.php', $root),
        sprintf('--mutation-gate-listing=%s', $file),
        '--no-ansi',
        '--suite=unit',
    ])
        ->and($shell->commands()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')))
        ->and($listing instanceof TestListing ? $listing->tests() : $listing)->toEqual(TestIds::of(TestId::of('A::a'), TestId::of('B::b')))
        ->and($listing instanceof TestListing ? $listing->inGroup(Group::named('holds:src/A.php')) : $listing)->toEqual(TestIds::of(TestId::of('A::a')));
});

it('cannot judge where Testo wrote no list, saying what it printed', function (): void {
    $framework = testoFramework();
    $file = sprintf('%s/listing.json', Scratch::directory());
    $listing = $framework->listing(new PhpUnitShellFake(static fn(): Ran => Ran::finished(succeeded: false, output: 'Testo said this')), Withheld::standard(), Suites::all(), $file);

    expect($listing instanceof CannotJudge ? $listing->why() : $listing)
        ->toBe(sprintf("Testo did not list the tests of the suite: %s holds no list of them. It said:\nTesto said this", $file));
});

it('holds a Testo to run only where its script is in the vendor directory, naming Composer\'s autoloader and list from its real root', function (): void {
    $root = (string) realpath(Scratch::directory());
    $installation = Installation::at(sprintf('%s/.', $root), Path::of('lib/vendor'));
    Scratch::write($root, 'packages/billing/lib/vendor/bin/testo', '<?php');

    expect($installation->autoloader())->toBe(sprintf('%s/lib/vendor/autoload.php', $root))
        ->and($installation->installed())->toBe(sprintf('%s/lib/vendor/composer/installed.json', $root))
        ->and($installation->hasTesto())->toBeFalse()
        ->and($installation->in(Path::of('packages/billing'))->hasTesto())->toBeTrue()
        ->and(Installation::at('/nowhere/at/all', Path::of('lib/vendor'))->autoloader())->toBe('/nowhere/at/all/lib/vendor/autoload.php');
});
