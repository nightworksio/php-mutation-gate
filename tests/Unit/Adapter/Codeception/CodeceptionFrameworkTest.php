<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Codeception\CodeceptionFramework;
use NightWorksIO\MutationGate\Adapter\Codeception\Extension;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\TestFiles;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\CoverageReport;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\HoldsReading;
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
use NightWorksIO\MutationGate\Tests\Support\NativeCodeception;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The project of a scratch root, its tests in tests/. */
function codeceptionProject(string $root): Project
{
    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::Codeception);
}

/** Codeception in a scratch project, at this version where one is given. */
function codeceptionFramework(string $version = ''): CodeceptionFramework
{
    $root = Scratch::directory();

    if ($version !== '') {
        Scratch::write($root, 'vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'codeception/codeception', 'version' => $version, 'source' => ['reference' => 'q']],
            ['name' => 'phpunit/php-code-coverage', 'version' => '14.4.1', 'source' => ['reference' => 'c']],
        ]]));
    }

    return NativeCodeception::in(codeceptionProject($root));
}

it('is Codeception, run as the codeception runner, defined by the Codeception config in the root, under either name', function (): void {
    $framework = codeceptionFramework();

    expect($framework->runner()->program())->toBe(Program::Codeception)
        ->and($framework->runner())->toBe(BuiltinRunner::Codeception)
        ->and($framework->definitions())->toEqual(Paths::of(Path::of('codeception.yml'), Path::of('codeception.dist.yml')))
        ->and($framework->tests())->toBeInstanceOf(TestFiles::class);
});

it('names the packages it drives at a version the runner supports, and cannot judge an older Codeception', function (): void {
    expect(codeceptionFramework('5.3.7')->versions())->toEqual(Versions::of(
        Version::of('codeception/codeception', '5.3.7', 'q'),
        Version::of('phpunit/php-code-coverage', '14.4.1', 'c'),
    ))
        ->and(codeceptionFramework('5.3.5')->versions())->toBeInstanceOf(CannotJudge::class);
});

it('runs Codeception\'s own script, which reads no config of PHP\'s settings, boots no warm worker and has no style a stub is written in', function (): void {
    $root = (string) realpath(Scratch::directory());
    $framework = NativeCodeception::in(codeceptionProject($root));

    expect($framework->script())->toBe(sprintf('%s/vendor/bin/codecept', $root))
        ->and($framework->errorDisplay())->toEqual(NotGiven::value())
        ->and($framework->config())->toEqual(NotGiven::value())
        ->and($framework->workerScript())->toEqual(NotGiven::value())
        ->and($framework->endedHidden('PHP Fatal error: Allowed memory size exhausted', configuredToHide: true))->toBeFalse()
        ->and($framework->testStyle())->toEqual(NotGiven::value())
        ->and($framework->holds())->toBe(HoldsReading::Listed)
        ->and($framework->report())->toBe(CoverageReport::Php);
});

it('is Codeception where Composer installed its script, and in a package where Composer installed it there', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'packages/billing/vendor/bin/codecept', '<?php');
    $framework = NativeCodeception::in(codeceptionProject($root));
    $billing = new TestFiles(codeceptionProject(sprintf('%s/packages/billing', $root)));

    expect($framework->isInstalled())->toBeFalse()
        ->and($framework->in(Path::of('packages/billing'), $billing)->isInstalled())->toBeTrue()
        ->and($framework->in(Path::of('packages/billing'), $billing)->tests())->toBe($billing)
        ->and($framework->in(Path::of('packages/none'), $billing)->isInstalled())->toBeFalse();
});

it('selects the covering tests by their ids, exactly, from a file the extension reads by its path', function (): void {
    $selection = codeceptionFramework()->selection(TestIds::of(TestId::of('Tests\MoneyTest::testAdds'), TestId::of('Tests\MoneyCest::rounds#1')));

    expect([$selection->file(), $selection->lines(), $selection->isExact(), $selection->option('/g/selected.txt')])
        ->toBe(['selected.txt', ['Tests\MoneyTest::testAdds', 'Tests\MoneyCest::rounds#1'], true, '/g/selected.txt']);
});

it('starts Codeception\'s script with the extension enabled for a mutant, a start-up and a coverage run', function (): void {
    $root = (string) realpath(Scratch::directory());
    $framework = NativeCodeception::in(codeceptionProject($root));
    $started = [sprintf('%s/vendor/bin/codecept', $root), 'run', '--no-interaction', '--no-colors', '--ext', Extension::class, '--override'];
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));

    expect(array_slice($framework->mutant('/s', $request), 0, 7))->toBe($started)
        ->and($framework->mutant('/s', $request)[7])->toContain('"in-group":"holds:src/Money.php"')
        ->and(array_slice($framework->startUp('/s'), 0, 7))->toBe($started)
        ->and($framework->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')), '/m.php')[7])
        ->toContain(sprintf('"coverage-map":"/m.php","vendor-directory":"%s/vendor"', $root));
});

it('lists every test and its groups as the extension wrote them, through PHP, withholding what the run withholds, from every suite', function (): void {
    $root = (string) realpath(Scratch::directory());
    $framework = NativeCodeception::in(codeceptionProject($root));
    $file = sprintf('%s/listing.json', Scratch::directory());
    $shell = new PhpUnitShellFake(static function () use ($file): Ran {
        file_put_contents($file, (string) json_encode(['listed' => ['Tests\ATest::testA', 'Tests\BCest::b'], 'groups' => ['holds:src/A.php' => ['Tests\ATest::testA']]]));

        return Ran::finished(succeeded: true, output: 'Tests: 2, Skipped: 2.');
    });
    $listing = $framework->listing($shell, Withheld::of('SECRET'), Suites::listed('Unit'), $file);

    expect($shell->commands()[0]->arguments())->toBe([
        PHP_BINARY,
        sprintf('%s/vendor/bin/codecept', $root),
        'run',
        '--no-interaction',
        '--no-colors',
        '--ext',
        Extension::class,
        '--override',
        sprintf('extensions: config: %s: {"listing-file":"%s"}', Extension::class, $file),
    ])
        ->and($shell->commands()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')))
        ->and($listing instanceof TestListing ? $listing->tests() : $listing)->toEqual(TestIds::of(TestId::of('Tests\ATest::testA'), TestId::of('Tests\BCest::b')))
        ->and($listing instanceof TestListing ? $listing->inGroup(Group::named('holds:src/A.php')) : $listing)->toEqual(TestIds::of(TestId::of('Tests\ATest::testA')));
});

it('cannot judge where Codeception failed, wrote no list, or wrote one with no tests, saying what it printed', function (bool $succeeded, string $written): void {
    $framework = codeceptionFramework();
    $file = sprintf('%s/listing.json', Scratch::directory());
    $shell = new PhpUnitShellFake(static function () use ($file, $succeeded, $written): Ran {
        if ($written !== '') {
            file_put_contents($file, $written);
        }

        return Ran::finished(succeeded: $succeeded, output: 'Codeception said this');
    });
    $listing = $framework->listing($shell, Withheld::standard(), Suites::all(), $file);

    expect($listing instanceof CannotJudge ? $listing->why() : $listing)
        ->toBe(sprintf("Codeception did not list the tests of the suite: %s holds no list of them. It said:\nCodeception said this", $file));
})->with([
    'failed' => [false, '{"listed":[]}'],
    'wrote nothing' => [true, ''],
    'wrote no JSON' => [true, 'not json'],
    'wrote no tests' => [true, '{"groups":{}}'],
]);
