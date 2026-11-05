<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\UnmutatedRuns;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\NativePhpUnit;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// A control forked from a warm worker finds what a control in a fresh process
// finds (ADR-0070), with a real PHPUnit and a real patched Pest: the same
// end and the same failing tests, run by run. Under PHPUnit the controls run
// a test that fails where an earlier run in its process left anything behind,
// and the worker boots once where each fresh control boots for itself.

afterEach(function (): void {
    Scratch::sweep();
});

/** The library whose vendor directory the PHPUnit project runs on, and the files of its warm project. */
const WARM_CONTROLS_LIBRARY = 'tests/Contract/Runner/phpunit-fixture';

/** A request of src/Counter.php in one place, its runs started as this says. */
function warmControlsRequest(string $source, Workers $workers): MutationRequest
{
    return MutationRequest::of(Paths::of(Path::of($source)), WholeSuite::tests())->across(Pool::of(ProcessCount::single(), $workers));
}

/** @return list<array{string, list<string>}> each control's end and the tests that failed in it */
function warmControlsFound(ControlRuns|CannotJudge $runs, Control ...$controls): array
{
    return $runs instanceof ControlRuns ? array_values(array_map(static function (Control $control) use ($runs): array {
        $run = $runs->of($control);

        return [$run->end()->value, array_map(static fn(TestId $test): string => $test->value(), [...$run->failing()])];
    }, $controls)) : [[$runs->why(), []]];
}

/**
 * A PHPUnit project of the library's warm files, on its vendor directory,
 * whose bootstrap appends a line to boots.txt each time it runs: its root.
 */
function warmControlsProject(): string
{
    $root = (string) realpath(Scratch::directory());
    $files = Tree::at(sprintf('%s/warm', WARM_CONTROLS_LIBRARY));
    symlink(Tree::at(sprintf('%s/vendor', WARM_CONTROLS_LIBRARY)), sprintf('%s/vendor', $root));
    Scratch::write($root, 'src/Counter.php', (string) file_get_contents(sprintf('%s/Counter.php', $files)));
    Scratch::write($root, 'tests/CounterSpec.php', (string) file_get_contents(sprintf('%s/CounterSpec.php', $files)));
    Scratch::write($root, 'bootstrap.php', "<?php\n\nrequire __DIR__ . '/vendor/autoload.php';\n\n"
        . "file_put_contents(__DIR__ . '/boots.txt', \"booted\\n\", FILE_APPEND);\n\n"
        . "spl_autoload_register(static function (string \$class): void {\n"
        . "    \$file = sprintf('%s/src/%s.php', __DIR__, substr(\$class, strlen('Warm\\\\')));\n"
        . "    if (str_starts_with(\$class, 'Warm\\\\') && is_file(\$file)) {\n        require \$file;\n    }\n});\n");
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

it('finds of PHPUnit controls forked from a warm worker what it finds of them in fresh processes, booting once', function (): void {
    $controls = [
        Control::of(Path::of('src/Counter.php'), TestIds::of(TestId::of('Warm\Tests\CounterSpec::adds')), Seconds::of(30.0)),
        Control::of(Path::of('src/Counter.php'), TestIds::of(TestId::of('Warm\Tests\CounterSpec::adds')), Seconds::of(31.0)),
    ];
    $found = [];
    $boots = [];

    foreach ([Workers::Fork, Workers::Mutants] as $workers) {
        $root = warmControlsProject();
        $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit);
        $shell = new ProcessShell(new LocalProcesses(new SystemClock()), $root, getenv());
        $runs = new UnmutatedRuns($project, $shell, NativePhpUnit::in($project), new CapDirectory())
            ->of(warmControlsRequest('src/Counter.php', $workers), Controls::of(...$controls));
        $found[$workers->value] = warmControlsFound($runs, ...$controls);
        $boots[$workers->value] = substr_count((string) file_get_contents(sprintf('%s/boots.txt', $root)), 'booted');
    }

    expect($found['fork'])->toBe($found['mutants'])
        ->and($found['fork'])->toBe([['passed', []], ['passed', []]])
        ->and($boots)->toBe(['fork' => 1, 'mutants' => 2]);
})->skip(fn(): bool => ! is_dir(Tree::at(sprintf('%s/vendor', WARM_CONTROLS_LIBRARY))) || ! function_exists('pcntl_fork'), 'the PHPUnit runner contracts job installs the PHPUnit library');

it('finds of patched Pest controls forked from a warm worker what it finds of them in fresh processes, its child\'s peak written', function (): void {
    $test = 'P\Tests\MoneySpec::__pest_evaluable_it_adds_two_amounts';
    $controls = [
        Control::of(Path::of('src/Money.php'), TestIds::of(TestId::of($test)), Seconds::of(60.0)),
        Control::of(Path::of('src/Money.php'), TestIds::of(TestId::of($test)), Seconds::of(0.01)),
    ];
    $library = Library::pest(Patching::on(Library::canary()));
    $forkedPeak = sprintf('%s/.mutation-gate/pest/controls/forked-peak-0', $library->root());
    $found = [];
    $peaks = [];

    foreach ([Workers::Fork, Workers::Mutants] as $workers) {
        $runs = $library->runner()->controls(warmControlsRequest('src/Money.php', $workers), Controls::of(...$controls));
        $found[$workers->value] = warmControlsFound($runs, ...$controls);
        $peaks[$workers->value] = is_file($forkedPeak) && (int) file_get_contents($forkedPeak) > 0;
    }

    expect($found['fork'])->toBe($found['mutants'])
        ->and($found['fork'])->toBe([['passed', []], ['ran-out', []]])
        ->and($peaks)->toBe(['fork' => true, 'mutants' => false]);
})->skip(fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork'), 'the runner contracts job installs the fixture library');
