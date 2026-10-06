<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Override;
use NightWorksIO\MutationGate\Adapter\PhpUnit\PreparedRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\ProcessShell;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workforce;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutants;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitScan;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// What warm workers do with a real PHPUnit (ADR-0023, decisions 12 to 14),
// at the lowest and the highest PHPUnit the runner contracts install: each
// mutant run in a child forked from a booted worker comes to the verdict a
// fresh process gives it, no child's state reaches the next, and a boot the
// guard refuses forks nothing and is warned of. A project of its own in a
// scratch directory runs on phpunit-fixture/'s vendor directory.

afterEach(function (): void {
    Scratch::sweep();
});

/** The PHPUnit library whose vendor directory each warm project runs on. */
const WARM_LIBRARY = 'tests/Contract/Runner/phpunit-fixture';

/**
 * The warm project's files, in the library: Counter.php, a source file with
 * two sums, one its test checks and one it runs and checks nothing of;
 * CountingUp.php, the same class whose sum counts up to its second term,
 * which loops for ever where it counts down; and CounterSpec.php, a test of
 * both sums that fails where an earlier run in its process left anything
 * behind: a static set, a variable put in the environment, or a class
 * declared. Run once in a fresh process, it never fails for that.
 */
const WARM_FILES = 'tests/Contract/Runner/phpunit-fixture/warm';

function isWarmLibraryInstalled(): bool
{
    return is_dir(Tree::at(sprintf('%s/vendor', WARM_LIBRARY))) && function_exists('pcntl_fork');
}

/** A project in a new directory, on the library's vendor directory, whose bootstrap runs these lines first. */
function warmProject(string $boot = '', string $source = 'Counter.php'): string
{
    $root = (string) realpath(Scratch::directory());
    symlink(Tree::at(sprintf('%s/vendor', WARM_LIBRARY)), sprintf('%s/vendor', $root));
    Scratch::write($root, 'src/Counter.php', (string) file_get_contents(Tree::at(sprintf('%s/%s', WARM_FILES, $source))));
    Scratch::write($root, 'tests/CounterSpec.php', (string) file_get_contents(Tree::at(sprintf('%s/CounterSpec.php', WARM_FILES))));
    Scratch::write($root, 'bootstrap.php', sprintf(
        "<?php\n\nrequire __DIR__ . '/vendor/autoload.php';\n\nspl_autoload_register(static function (string \$class): void {\n"
        . "    \$file = sprintf('%%s/src/%%s.php', __DIR__, substr(\$class, strlen('Warm\\\\')));\n"
        . "    if (str_starts_with(\$class, 'Warm\\\\') && is_file(\$file)) {\n        require \$file;\n    }\n});\n\n%s\n",
        $boot,
    ));
    Scratch::write($root, 'phpunit.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="bootstrap.php">
            <testsuites>
                <testsuite name="Warm">
                    <directory suffix="Spec.php">tests</directory>
                </testsuite>
            </testsuites>
        </phpunit>
        XML);

    return $root;
}

/**
 * The project's two runs, of the sum its test checks and of the one it checks nothing of, ready to start.
 *
 * @return list<PreparedRun|Mutant|CannotJudge>
 */
function warmRuns(Project $project, MutantRun $run, MutationRequest $request, Seconds $limit): array
{
    $made = Engine::with(new PlusToMinus())->mutantsOf(
        Path::of('src/Counter.php'),
        Contents::of((string) file_get_contents($project->absolute(Path::of('src/Counter.php')))),
    );
    $covering = TestIds::of(TestId::of('Warm\Tests\CounterSpec::adds'));
    $runs = [];

    foreach ($made instanceof MadeMutants ? [...$made] : [] as $mutant) {
        $runs[] = $run->prepared($mutant, $covering, $request, $limit);
    }

    return $runs;
}

/**
 * The project's runs, in warm workers in this many places, and in fresh
 * processes, by their verdicts; and what the workers warned of.
 *
 * @param  positive-int                                                $places
 * @return array{list<list<string>>, list<list<string>>, list<string>}
 */
function warmlyAndFreshly(string $root, int $places = 1, float $limit = 30.0): array
{
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
    $override = Override::writtenFor($project);
    $shell = new ProcessShell(new LocalProcesses(new SystemClock()), $root, getenv());
    $invocation = new Invocation($project, $override instanceof CannotJudge ? '' : $override);
    $scan = PhpUnitScan::uncapped($project);
    $run = new MutantRun($project, $shell, $invocation, new TestFiles($project), $scan, NotGiven::value());
    $request = MutationRequest::of(Paths::of(Path::of('src/Counter.php')), WholeSuite::tests())
        ->across(Pool::of(ProcessCount::of($places), Workers::Fork));
    $runs = array_values(array_filter(warmRuns($project, $run, $request, Seconds::of($limit)), static fn(mixed $prepared): bool => $prepared instanceof PreparedRun));
    $forked = new Workforce($project, $shell, $invocation, $scan, $run)->judged($request, PHP_INT_MAX, $runs);
    $warm = [];

    foreach (array_keys($runs) as $at) {
        $judged = $forked->judgedAt($at);
        $warm[] = $judged instanceof Mutant ? warmVerdict($judged) : ['not forked'];
    }

    $fresh = array_map(static fn(PreparedRun $prepared): array => warmVerdict($run->judged(
        $prepared->made(),
        $prepared->covering(),
        $request,
        Seconds::of($limit),
    )), $runs);
    $warnings = array_map(static fn(Warning $warning): string => $warning->text(), [...$forked->warnings()]);

    return [$warm, $fresh, $warnings];
}

/** @return list<string> a mutant's status and the tests that killed it, or why it is unjudged */
function warmVerdict(Mutant|CannotJudge $mutant): array
{
    return match (true) {
        $mutant instanceof CannotJudge => [$mutant->why()],
        $mutant->reason() instanceof Reason => [$mutant->status()->value, $mutant->reason()->text()],
        default => [$mutant->status()->value, ...array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()])],
    };
}

it('judges each mutant in a child of a booted worker, through PHPUnit\'s own Loader, PhpHandler and Application, as a fresh process judges it, and warns of nothing', function (int $places): void {
    [$warm, $fresh, $warnings] = warmlyAndFreshly(warmProject(), max(1, $places));

    expect($warm)->toBe([['killed', 'Warm\Tests\CounterSpec::adds'], ['survived']])
        ->and($warm)->toBe($fresh)
        ->and($warnings)->toBe([]);
})->with(['one worker' => [1], 'two workers' => [2]])
    ->skip(! isWarmLibraryInstalled(), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');

it('carries nothing one child left, in memory or in the environment, into the next child of its worker', function (): void {
    // One worker runs both mutants, one after the other: the test fails in the second where the first left its
    // static, its variable or its class behind, which would turn the second's survivor into a kill.
    [$warm] = warmlyAndFreshly(warmProject(), 1);

    expect($warm[1])->toBe(['survived']);
})->skip(! isWarmLibraryInstalled(), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');

it('forks nothing from a boot that leaves a socket open, and warns of it once', function (): void {
    [$warm, , $warnings] = warmlyAndFreshly(warmProject("\$GLOBALS['warmSocket'] = stream_socket_server('tcp://127.0.0.1:0');"), 2);

    expect($warm)->toBe([['not forked'], ['not forked']])
        ->and($warnings)->toBe(['The boot left 1 socket open once bootstrap.php ran, which every forked child would share, so each mutant ran fresh.']);
})->skip(! isWarmLibraryInstalled(), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');

it('forks nothing from a boot that loads a file the run mutates, and warns of it', function (): void {
    [$warm, , $warnings] = warmlyAndFreshly(warmProject("class_exists('Warm\\\\Counter');"));

    expect($warm)->toBe([['not forked'], ['not forked']])
        ->and($warnings)->toBe(['The boot loaded src/Counter.php, which this run mutates, at bootstrap.php:12, so each mutant ran fresh.']);
})->skip(! isWarmLibraryInstalled(), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');

it('forks nothing from a boot that fails, and warns of how the worker failed', function (): void {
    [$warm, , $warnings] = warmlyAndFreshly(warmProject("throw new RuntimeException('the bootstrap broke');"));

    expect($warm)->toBe([['not forked'], ['not forked']])
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0] ?? '')->toStartWith('A warm worker failed, so the mutants it left ran fresh. It said: ')
        ->and($warnings[0] ?? '')->toContain('the bootstrap broke');
})->skip(! isWarmLibraryInstalled(), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');

it('stops a child at its mutant\'s limit, and judges it as a fresh process judges a run stopped so', function (): void {
    [$warm, $fresh, $warnings] = warmlyAndFreshly(warmProject(source: 'CountingUp.php'), 1, 2.0);

    expect($warm)->toBe([['timed-out'], ['killed', 'Warm\Tests\CounterSpec::adds']])
        ->and($warm)->toBe($fresh)
        ->and($warnings)->toBe([]);
})->skip(! isWarmLibraryInstalled(), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');

it('forks nothing from a boot that started PHPUnit\'s events, and warns of it', function (): void {
    [$warm, , $warnings] = warmlyAndFreshly(warmProject('PHPUnit\Event\Facade::emitter();'));

    expect($warm)->toBe([['not forked'], ['not forked']])
        ->and($warnings)->toBe(['The boot started PHPUnit\'s events once bootstrap.php ran, which every child would inherit, so each mutant ran fresh.']);
})->skip(! isWarmLibraryInstalled(), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');
