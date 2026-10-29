<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\BitwiseOrToBitwiseAnd;
use Pest\Mutate\Mutators\Logical\BooleanAndToBooleanOr;
use Pest\Mutate\Mutators\Logical\FalseToTrue;

$holds = [
    'holds:src/Adapter/Pest',
];

/**
 * Each mutant of a mutator in one fixture file, run with Pest patched on the map of a coverage run of the fixture's
 * spec: its line, its status and the tests that killed it; or why the run cannot judge them.
 *
 * @param class-string $mutator
 *
 * @return list<array{int, string, list<string>}>|string
 */
function missedMutants(string $file, string $mutator): array|string
{
    $copied = ['src/Lettering.php', 'src/Screen.php', 'src/Marked.php', 'tests/MissedSpec.php'];

    foreach ($copied as $each) {
        copy(Tree::at(sprintf('tests/Contract/Runner/reach/%s', $each)), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $each)));
    }

    Patch::applyIn(Library::vendor());
    $runner = Library::pest(Patching::on(Library::canary()))->runner();
    $mutated = Paths::of(Path::of($file));
    $directory = Path::of('.mutation-gate/missed');

    try {
        $map = $runner->coverage(CoverageRun::of(WholeSuite::tests(), $directory));
        file_put_contents(
            Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in($directory)->value())),
            CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::empty(), Unplaced::map()),
        );
        $result = $runner->mutate(MutationRequest::of($mutated, WholeSuite::tests())
            ->narrowedTo($mutated, Narrowing::none()->toMutators(Mutators::named($mutator)))
            ->reusingCoverage(Handed::maps($directory, $directory)));
    } finally {
        foreach ($copied as $each) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $each)));
        }
    }

    return $result instanceof CannotJudge ? $result->why() : array_map(
        static fn(Mutant $mutant): array => [
            $mutant->location()->start()->number(),
            $mutant->status()->value,
            array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]),
        ],
        [...$result->mutants()],
    );
}

it('kills the condition of a match\'s first arm by a test whose result is a later arm, with Pest patched', function (): void {
    expect(missedMutants('src/Lettering.php', BooleanAndToBooleanOr::class))->toBe([
        [17, 'killed', ['P\Tests\MissedSpec::__pest_evaluable_it_letters_a_long_title_small']],
    ]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('kills a property\'s default by a test that makes its class by reflection, which runs its constructor, with Pest patched', function (): void {
    expect(missedMutants('src/Screen.php', FalseToTrue::class))->toBe([
        [10, 'killed', ['P\Tests\MissedSpec::__pest_evaluable_it_makes_a_screen_closed']],
    ]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('kills the argument of `#[Attribute]` by a test that names the attribute and makes it, with Pest patched', function (): void {
    expect(missedMutants('src/Marked.php', BitwiseOrToBitwiseAnd::class))->toBe([
        [9, 'killed', ['P\Tests\MissedSpec::__pest_evaluable_it_marks_a_screen_s_title']],
    ]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);
