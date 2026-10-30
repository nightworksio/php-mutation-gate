<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\HeavyMutants;
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
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Equality\GreaterToGreaterOrEqual;
use PHPUnit\Framework\Assert;

$holds = [
    'holds:src/Adapter/Pest',
];

/**
 * The heavy mutants of a result, judged part after part by a runner's controls, as a run's flow judges them.
 *
 * @return list<array{string, list<string>}>
 */
function heavyJudged(Runner $runner, MutationRequest $request, Mutants $mutants, CoverageMap $map, LimitBounds $bounds): array
{
    $tests = $runner->testsIn(Paths::of(Path::of('tests/HeavySpec.php')), $map);
    $places = TestPlaces::none()->placing(Path::of('tests/HeavySpec.php'), $tests instanceof TestIds ? $tests : TestIds::none());
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

    for ($asked = $heavy->asked(); count($asked) > 0; $asked = $heavy->asked()) {
        $runs = $runner->controls($request, $asked);
        $heavy = $heavy->after($runs instanceof ControlRuns ? $runs : Assert::fail('The controls cannot run.'), Controls::none());
    }

    return array_map(
        static fn(Mutant $mutant): array => [
            $mutant->status()->value,
            array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]),
        ],
        [...$heavy->applied($mutants)],
    );
}

/**
 * The heavy file's mutants as the runner's run of them found them, on the map of a coverage run of the fixture, and
 * as their parts judged them.
 *
 * @return array{Mutants, list<array{string, list<string>}>}
 */
function heavyRun(Runner $runner, LimitBounds $bounds): array
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
        ->reusingCoverage(Handed::maps($directory, $directory));
    $result = $runner->mutate($request);
    $mutants = $result instanceof MutationResult ? $result->mutants() : Assert::fail('The run cannot judge.');

    return [$mutants, heavyJudged($runner, $request, $mutants, $map, $bounds)];
}

// Four tests of most of two seconds each judge the comparison in
// src/Heavy.php: together they run past the most of six seconds a mutant is
// allowed, and one alone fits in it. Only the slowest, which runs last,
// kills the mutant that weighs a kilogram heavy. The mutant's own run times
// out at the most, and judged part after part, each part a test, it is
// killed by that test in the last part.
it('kills a mutant whose tests run past the most only together, by its last part, with patched Pest', function (): void {
    $copied = ['src/Heavy.php', 'tests/HeavySpec.php'];

    foreach ($copied as $file) {
        copy(Tree::at(sprintf('tests/Contract/Runner/heavy/%s', $file)), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
    }

    Patch::applyIn(Library::vendor());
    $bounds = LimitBounds::between(Seconds::of(1.0), Seconds::of(6.0))->startingIn(Seconds::of(0.1));
    $runner = Library::pestWithin(Patching::on(Library::canary()), $bounds, 'pest patched, heavy')->runner();
    try {
        [$mutants, $judged] = heavyRun($runner, $bounds);
    } finally {
        foreach ($copied as $file) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
        }
    }

    expect(array_map(static fn(Mutant $mutant): string => $mutant->status()->value, [...$mutants]))
        ->toBe([MutantStatus::TimedOut->value])
        ->and($judged)->toBe([
            [MutantStatus::Killed->value, ['P\Tests\HeavySpec::__pest_evaluable_it_weighs_a_kilogram_light']],
        ]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);
