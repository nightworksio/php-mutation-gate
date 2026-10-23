<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpSpec\PhpSpecFramework;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
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
use NightWorksIO\MutationGate\Tests\Support\NativePhpSpec;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/** The project of a scratch root, its specs in spec/. */
function phpSpecProject(string $root): Project
{
    return Project::at($root, Paths::of(Path::of('spec')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpSpec);
}

/** PhpSpec in a scratch project, at this version where one is given. */
function phpSpecFramework(string $version = ''): PhpSpecFramework
{
    $root = Scratch::directory();

    if ($version !== '') {
        Scratch::write($root, 'vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'phpspec/phpspec', 'version' => $version, 'source' => ['reference' => 'p']],
            ['name' => 'phpunit/php-code-coverage', 'version' => '14.4.1', 'source' => ['reference' => 'c']],
        ]]));
    }

    return NativePhpSpec::in(phpSpecProject($root));
}

it('is PhpSpec, run as the phpspec runner, defined by the PhpSpec config in the root, whichever name it has', function (): void {
    $framework = phpSpecFramework();

    expect($framework->program())->toBe(Program::PhpSpec)
        ->and($framework->runner())->toBe(BuiltinRunner::PhpSpec)
        ->and($framework->definitions())->toEqual(Paths::of(...array_map(Path::of(...), ['phpspec.yml', '.phpspec.yml', 'phpspec.yml.dist', 'phpspec.yaml', '.phpspec.yaml', 'phpspec.yaml.dist'])))
        ->and($framework->tests())->toBeInstanceOf(TestFiles::class);
});

it('names the packages it drives at a version the runner supports, and cannot judge an older PhpSpec', function (): void {
    expect(phpSpecFramework('8.3.1')->versions())->toEqual(Versions::of(
        Version::of('phpspec/phpspec', '8.3.1', 'p'),
        Version::of('phpunit/php-code-coverage', '14.4.1', 'c'),
    ))
        ->and(phpSpecFramework('8.1.0')->versions())->toBeInstanceOf(CannotJudge::class);
});

it('runs the gate\'s launcher, which reads no config of PHP\'s settings and boots no warm worker, and writes specs', function (): void {
    $framework = phpSpecFramework();

    expect($framework->script())->toBe(Tree::at('bin/mutation-gate-phpspec'))
        ->and(is_file(Tree::at('bin/mutation-gate-phpspec')))->toBeTrue()
        ->and($framework->errorDisplay())->toEqual(NotGiven::value())
        ->and($framework->config())->toEqual(NotGiven::value())
        ->and($framework->workerScript())->toEqual(NotGiven::value())
        ->and($framework->endedHidden('PHP Fatal error: Allowed memory size exhausted', configuredToHide: true))->toBeFalse()
        ->and($framework->testStyle())->toBe(AssertionStyle::PhpSpec);
});

it('is PhpSpec where Composer installed its script, and in a package where Composer installed it there', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'packages/billing/vendor/bin/phpspec', '<?php');
    $framework = NativePhpSpec::in(phpSpecProject($root));
    $billing = new TestFiles(phpSpecProject(sprintf('%s/packages/billing', $root)));

    expect($framework->isInstalled())->toBeFalse()
        ->and($framework->in(Path::of('packages/billing'), $billing)->isInstalled())->toBeTrue()
        ->and($framework->in(Path::of('packages/billing'), $billing)->tests())->toBe($billing)
        ->and($framework->in(Path::of('packages/none'), $billing)->isInstalled())->toBeFalse();
});

it('selects the covering examples by their ids, exactly, from a file the launcher reads', function (): void {
    $selection = phpSpecFramework()->selection(TestIds::of(TestId::of('spec\MoneySpec::it_adds'), TestId::of('spec\MoneySpec::it_rounds')));

    expect([$selection->file(), $selection->lines(), $selection->isExact(), $selection->option('/g/examples.txt')])
        ->toBe(['examples.txt', ['spec\MoneySpec::it_adds', 'spec\MoneySpec::it_rounds'], true, '--mutation-gate-examples=/g/examples.txt']);
});

it('starts its launcher with the project\'s autoloader for a mutant, a start-up and a coverage run', function (): void {
    $root = (string) realpath(Scratch::directory());
    $framework = NativePhpSpec::in(phpSpecProject($root));
    $launched = [Tree::at('bin/mutation-gate-phpspec'), sprintf('%s/vendor/autoload.php', $root)];
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));

    expect(array_slice($framework->mutant('--sel', $request), 0, 4))->toBe([...$launched, '--sel', '--mutation-gate-group=holds:src/Money.php'])
        ->and(array_slice($framework->startUp('--sel'), 0, 3))->toBe([...$launched, '--sel'])
        ->and(array_slice($framework->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')), '/m.php'), 0, 3))
        ->toBe([...$launched, '--mutation-gate-coverage=/m.php']);
});

it('lists every example and its holding groups as the launcher wrote them, through PHP, withholding what the run withholds', function (): void {
    $root = (string) realpath(Scratch::directory());
    $framework = NativePhpSpec::in(phpSpecProject($root));
    $file = sprintf('%s/listing.json', Scratch::directory());
    $shell = new PhpUnitShellFake(static function () use ($file): Ran {
        file_put_contents($file, (string) json_encode(['listed' => ['spec\A::it_a', 'spec\B::it_b'], 'groups' => ['holds:src/A.php' => ['spec\A::it_a']]]));

        return Ran::finished(succeeded: true, output: '2 examples (2 skipped)');
    });
    $listing = $framework->listing($shell, Withheld::of('SECRET'), Suites::listed('Unit'), $file);

    expect($shell->commands()[0]->arguments())->toBe([PHP_BINARY, $framework->script(), sprintf('%s/vendor/autoload.php', $root), sprintf('--mutation-gate-listing=%s', $file), 'run', '--no-interaction', '--no-code-generation', '--no-rerun', '--format=dot'])
        ->and($shell->commands()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')))
        ->and($listing instanceof TestListing ? $listing->tests() : $listing)->toEqual(TestIds::of(TestId::of('spec\A::it_a'), TestId::of('spec\B::it_b')))
        ->and($listing instanceof TestListing ? $listing->inGroup(Group::named('holds:src/A.php')) : $listing)->toEqual(TestIds::of(TestId::of('spec\A::it_a')));
});

it('cannot judge where PhpSpec failed, wrote no list, or wrote one with no examples, saying what it printed', function (bool $succeeded, string $written): void {
    $framework = phpSpecFramework();
    $file = sprintf('%s/listing.json', Scratch::directory());
    $shell = new PhpUnitShellFake(static function () use ($file, $succeeded, $written): Ran {
        if ($written !== '') {
            file_put_contents($file, $written);
        }

        return Ran::finished(succeeded: $succeeded, output: 'PhpSpec said this');
    });
    $listing = $framework->listing($shell, Withheld::standard(), Suites::all(), $file);

    expect($listing instanceof CannotJudge ? $listing->why() : $listing)
        ->toBe(sprintf("PhpSpec did not list the tests of the suite: %s holds no list of them. It said:\nPhpSpec said this", $file));
})->with([
    'failed' => [false, '{"listed":[]}'],
    'wrote nothing' => [true, ''],
    'wrote no JSON' => [true, 'not json'],
    'wrote no tests' => [true, '{"groups":{}}'],
]);
