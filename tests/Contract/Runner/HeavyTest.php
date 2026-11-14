<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\HeavyMutants;
use NightWorksIO\MutationGate\Core\Control\MutantParts;
use NightWorksIO\MutationGate\Core\Control\PartControls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Equality\GreaterOrEqualToGreater;
use Pest\Mutate\Mutators\Equality\GreaterToGreaterOrEqual;
use PHPUnit\Framework\Assert;

$holds = [
    'holds:src/Adapter/Pest',
];

/**
 * What a fixture's parts are judged within: limits a loaded machine has room to finish in, each laid on a start-up of
 * five seconds, and a most that one test's limit fits and no two tests' do. Each mutant's own run keeps the tight
 * bounds its runner was given, which a loaded machine only times out sooner.
 */
function heavyJudging(float $most): LimitBounds
{
    return LimitBounds::between(Seconds::of(1.0), Seconds::of($most))->startingIn(Seconds::of(5.0));
}

/**
 * The heavy mutants of a result, judged part after part by a runner's controls, as a run's flow judges them, and how
 * many tests each run held.
 *
 * @return array{list<array{string, list<string>}>, list<int>}
 */
function heavyJudged(
    Runner $runner,
    MutationRequest $request,
    Mutants $mutants,
    CoverageMap $map,
    LimitBounds $bounds,
    string ...$specs,
): array {
    $places = TestPlaces::none();

    foreach ($specs as $spec) {
        $tests = $runner->testsIn(Paths::of(Path::of($spec)), $map);
        $places = $places->placing(Path::of($spec), $tests instanceof TestIds ? $tests : TestIds::none());
    }

    $heavy = HeavyMutants::of(
        $mutants,
        $map,
        HeldCovered::none(),
        $runner->behaviour()->selection(),
        $bounds,
        static function (Mutant $mutant) use ($runner): Contents|CannotJudge {
            $checkable = $runner->checkable($mutant);

            return $checkable instanceof Checkable ? $checkable->mutant() : $checkable;
        },
        static fn(): TestPlaces => $places,
    );

    $rounds = [];

    for ($asked = $heavy->asked(); count($asked) > 0; $asked = $heavy->asked()) {
        $rounds = [...$rounds, ...array_map(static fn(Control $control): int => count($control->tests()), [...$asked])];
        $runs = $runner->controls($request, $asked);
        $heavy = $heavy->after($runs instanceof ControlRuns ? $runs : Assert::fail('The controls cannot run.'), Controls::none());
    }

    return [
        array_map(
            static fn(Mutant $mutant): array => [
                $mutant->status()->value,
                array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]),
            ],
            [...$heavy->applied($mutants)],
        ),
        $rounds,
    ];
}

/**
 * A fixture file's mutants of a mutator as the runner's run of them found them, on the map of a coverage run of the
 * fixture, and as their parts judged them within these bounds, each mutant taken to have run out at their most, the
 * files of these specs placing their tests, with how many tests each run of them held.
 *
 * @param  class-string                                                      $mutator
 * @return array{Mutants, list<array{string, list<string>}>, list<int>}
 */
function heavyRun(Runner $runner, LimitBounds $judging, string $file, string $mutator, string ...$specs): array
{
    $heavy = Paths::of(Path::of($file));
    $directory = Path::of('.mutation-gate/heavy');
    $measured = $runner->coverage(CoverageRun::of(WholeSuite::tests(), $directory));
    $map = $measured instanceof CoverageMap ? $measured : Assert::fail('The coverage run fails.');
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in($directory)->value())),
        CoverageMapFile::encode($map, Unplaced::map()),
    );
    $request = MutationRequest::of($heavy, WholeSuite::tests())
        ->narrowedTo($heavy, Narrowing::none()->toMutators(Mutators::named($mutator)))
        ->reusingCoverage(Handed::maps($directory, $directory));
    $result = $runner->mutate($request);
    $mutants = $result instanceof MutationResult ? $result->mutants() : Assert::fail('The run cannot judge.');

    $atTheMost = Mutants::of(...array_map(
        static fn(Mutant $mutant): Mutant => $mutant->withLimit($judging->most()),
        [...$mutants],
    ));

    return [$mutants, ...heavyJudged($runner, $request, $atTheMost, $map, $judging, ...$specs)];
}

// Four tests of most of two seconds each judge the comparison in
// src/Heavy.php: together they run past the most of six seconds a mutant is
// allowed. Only the slowest, which runs last, kills the mutant that weighs a
// kilogram heavy. The mutant's own run times out at the most, and judged part
// after part, each part a test, it is killed by that test.
it('kills a mutant whose tests run past the most only together, by its last part, with patched Pest', function (): void {
    $copied = ['src/Heavy.php', 'tests/HeavySpec.php'];

    foreach ($copied as $file) {
        copy(Tree::at(sprintf('tests/Contract/Runner/heavy/%s', $file)), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
    }

    Library::patched();
    $bounds = LimitBounds::between(Seconds::of(1.0), Seconds::of(6.0))->startingIn(Seconds::of(0.1));
    $runner = Library::pestWithin(Patching::on(Library::canary()), $bounds, 'pest patched, heavy')->runner();
    try {
        [$mutants, $judged, $rounds] = heavyRun($runner, heavyJudging(24.0), 'src/Heavy.php', GreaterToGreaterOrEqual::class, 'tests/HeavySpec.php');
    } finally {
        foreach ($copied as $file) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
        }
    }

    expect(array_map(static fn(Mutant $mutant): string => $mutant->status()->value, [...$mutants]))
        ->toBe([MutantStatus::TimedOut->value])
        ->and($judged)->toBe([
            [MutantStatus::Killed->value, ['P\Tests\HeavySpec::__pest_evaluable_it_weighs_a_kilogram_light']],
        ])
        ->and(array_unique($rounds))->toBe([1]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

/**
 * How the Heavy fixture's parts of its one mutant end, run one after another and at once in a pool of four, what fails
 * in them at once, and how long each took.
 *
 * @return array{list<string>, list<string>, list<list<string>>, int|float, int|float}
 */
function heavyPartsAtOnce(Runner $runner, LimitBounds $judging): array
{
    $heavy = Paths::of(Path::of('src/Heavy.php'));
    $directory = Path::of('.mutation-gate/heavy');
    $measured = $runner->coverage(CoverageRun::of(WholeSuite::tests(), $directory));
    $map = $measured instanceof CoverageMap ? $measured : Assert::fail('The coverage run fails.');
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in($directory)->value())),
        CoverageMapFile::encode($map, Unplaced::map()),
    );
    $request = MutationRequest::of($heavy, WholeSuite::tests())
        ->narrowedTo($heavy, Narrowing::none()->toMutators(Mutators::named(GreaterToGreaterOrEqual::class)))
        ->reusingCoverage(Handed::maps($directory, $directory))
        ->across(Pool::of(ProcessCount::of(4), Workers::Fresh));
    $result = $runner->mutate($request);
    $mutants = $result instanceof MutationResult ? $result->mutants() : Assert::fail('The run cannot judge.');
    $mutant = [...$mutants][0];
    $checkable = $runner->checkable($mutant);
    $text = $checkable instanceof Checkable ? $checkable->mutant() : Assert::fail('The mutant has no text.');
    $tests = $runner->testsIn(Paths::of(Path::of('tests/HeavySpec.php')), $map);
    $held = $tests instanceof TestIds ? $tests : Assert::fail('The spec lists no test.');
    $places = TestPlaces::none()->placing(Path::of('tests/HeavySpec.php'), $held);
    $parts = array_map(
        PartControls::of($mutant, $text, $map, $judging, $places)->serving(...),
        MutantParts::under($map, $judging)->of($mutant->location()->file(), $held, $places),
    );
    $alone = ControlRuns::none();
    $from = hrtime(as_number: true);

    foreach ($parts as $part) {
        $ran = $runner->controls($request, Controls::of($part));
        $alone = $alone->and($ran instanceof ControlRuns ? $ran : Assert::fail('A part cannot run alone.'));
    }

    $oneAfterAnother = hrtime(as_number: true) - $from;
    $from = hrtime(as_number: true);
    $ran = $runner->controls($request, Controls::of(...$parts));
    $atOnce = hrtime(as_number: true) - $from;
    $together = $ran instanceof ControlRuns ? $ran : Assert::fail('The parts cannot run at once.');
    $ends = static fn(ControlRuns $runs): array => array_map(
        static fn(Control $part): string => $runs->of($part)->end()->name,
        $parts,
    );

    return [
        $ends($alone),
        $ends($together),
        array_values(array_filter(array_map(
            static fn(Control $part): array => array_map(
                static fn(TestId $test): string => $test->value(),
                [...$together->of($part)->failing()],
            ),
            $parts,
        ))),
        $oneAfterAnother,
        $atOnce,
    ];
}

// The same mutant's parts run side by side: each part its own process with the
// mutant served, each ending as it does alone, the part holding the slowest
// test killing it and the others passing, in less time than one after another.
it('judges the parts of one mutant at once as it does one after another, with patched Pest', function (): void {
    $copied = ['src/Heavy.php', 'tests/HeavySpec.php'];

    foreach ($copied as $file) {
        copy(Tree::at(sprintf('tests/Contract/Runner/heavy/%s', $file)), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
    }

    Library::patched();
    $bounds = LimitBounds::between(Seconds::of(1.0), Seconds::of(6.0))->startingIn(Seconds::of(0.1));
    $runner = Library::pestWithin(Patching::on(Library::canary()), $bounds, 'pest patched, heavy at once')->runner();

    try {
        [$alone, $together, $failing, $oneAfterAnother, $atOnce] = heavyPartsAtOnce($runner, heavyJudging(24.0));
    } finally {
        foreach ($copied as $file) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
        }
    }

    expect(count($alone))->toBeGreaterThan(1)
        ->and($together)->toBe($alone)
        ->and($failing)->toBe([['P\\Tests\\HeavySpec::__pest_evaluable_it_weighs_a_kilogram_light']])
        ->and($atOnce)->toBeLessThan($oneAfterAnother);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

// Two quick spec files and a slower third judge the comparison in
// src/Split.php. Under mutation the second set-up in one process outlasts any
// limit here, which no test's own time holds. The mutant's own run times out
// at the most, and the two quick files make its first part, which runs out
// with the file unmutated too. Split in two by its files, each half fits, and
// the half of the file that tells kills the mutant that counts three few before
// the third file runs.
it('splits a part that runs out unmutated too by its files, and kills by the half that tells, with patched Pest', function (): void {
    $copied = ['src/Split.php', 'tests/SplitASpec.php', 'tests/SplitBSpec.php', 'tests/SplitCSpec.php'];

    foreach ($copied as $file) {
        copy(Tree::at(sprintf('tests/Contract/Runner/heavy/%s', $file)), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
    }

    Library::patched();
    $bounds = LimitBounds::between(Seconds::of(4.0), Seconds::of(6.0))->startingIn(Seconds::of(0.1));
    $runner = Library::pestWithin(Patching::on(Library::canary()), $bounds, 'pest patched, split')->runner();
    try {
        [$mutants, $judged, $rounds] = heavyRun(
            $runner,
            heavyJudging(21.0),
            'src/Split.php',
            GreaterOrEqualToGreater::class,
            'tests/SplitASpec.php',
            'tests/SplitBSpec.php',
            'tests/SplitCSpec.php',
        );
    } finally {
        foreach ($copied as $file) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
        }
    }

    expect(array_map(static fn(Mutant $mutant): string => $mutant->status()->value, [...$mutants]))
        ->toBe([MutantStatus::TimedOut->value])
        ->and($judged)->toBe([
            [MutantStatus::Killed->value, ['P\Tests\SplitCSpec::__pest_evaluable_it_counts_three_many']],
        ])
        ->and(array_slice($rounds, 0, 2))->toBe([2, 2])
        ->and(array_unique(array_slice($rounds, 2)))->toBe([1]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);
