<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Installed;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Override;
use NightWorksIO\MutationGate\Adapter\PhpUnit\ProcessShell;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutants;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Tests\Support\Mutators\DecrementToIncrement;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// What the PHPUnit runner's pieces do with a real PHPUnit (ADR-0023 decision
// 9), over the library in phpunit-fixture/, which the runner contracts job
// installs at the lowest and the highest PHPUnit the runner supports: PHPUnit
// started with the override, the extension and the covering tests' ids, and
// the extension's records read back into each mutant's verdict.

/** The library the PHPUnit runner mutates. */
const PHPUNIT_LIBRARY = 'tests/Contract/Runner/phpunit-fixture';

function isPhpUnitLibraryInstalled(): bool
{
    return is_dir(Tree::at(sprintf('%s/vendor', PHPUNIT_LIBRARY)));
}

/**
 * One mutant of a library file, the nth the mutator makes, judged by these of
 * the library's tests.
 */
function judgedByPhpUnit(Mutator $mutator, string $file, int $nth, string $covering, float $limit = 30.0): Mutant|CannotJudge
{
    $project = Project::at(Tree::at(PHPUNIT_LIBRARY), Path::of('vendor'), Path::of('.mutation-gate'));
    $override = Override::writtenFor($project);
    $made = Engine::with($mutator)->mutantsOf(
        Path::of($file),
        Contents::of((string) file_get_contents($project->absolute(Path::of($file)))),
    );

    if ($override instanceof CannotJudge || ! $made instanceof MadeMutants) {
        return CannotJudge::because('The library cannot be mutated.');
    }

    return new MutantRun($project, new ProcessShell($project->root(), getenv()), new Invocation($project, $override))->judged(
        [...$made][$nth],
        TestIds::of(TestId::of(sprintf('Tests\%s', $covering))),
        MutationRequest::of(Paths::of(Path::of($file)), WholeSuite::tests()),
        Seconds::of($limit),
    );
}

/** @return list<string> a mutant's status and the tests that killed it */
function verdictOf(Mutant|CannotJudge $mutant): array
{
    return $mutant instanceof Mutant
        ? [$mutant->status()->value, ...array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()])]
        : [$mutant->why()];
}

it('drives a PHPUnit the runner supports', function (): void {
    expect(Installed::versionsIn(Tree::at(sprintf('%s/vendor/composer/installed.json', PHPUNIT_LIBRARY))))
        ->toBeInstanceOf(Versions::class);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('serves a mutant of a class to the test that kills it, and names that test', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 0, 'MoneySpec::addsTwoAmounts')))
        ->toBe(['killed', 'Tests\MoneySpec::addsTwoAmounts']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('serves a mutant of a function Composer loads before anything else', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/helpers.php', 0, 'MoneySpec::doublesThroughAFunctionComposerLoads')))
        ->toBe(['killed', 'Tests\MoneySpec::doublesThroughAFunctionComposerLoads']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('lets a mutant survive the test that runs it and checks nothing of it', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 1, 'MoneySpec::countsWithoutSayingSo')))
        ->toBe(['survived']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('passes a test\'s own locking, truncating, touching and listing of files while it stands in for file://', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 1, 'FilesSpec::locksTruncatesTouchesAndListsFiles')))
        ->toBe(['survived']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('stops a mutant that never ends at its limit', function (): void {
    expect(verdictOf(judgedByPhpUnit(new DecrementToIncrement(), 'src/Money.php', 0, 'MoneySpec::drainsAnAmountToNothing', 3.0)))
        ->toBe(['timed-out']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('leaves unjudged a mutant whose tests\' ids match no test', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 0, 'MoneySpec::noSuchTest')))
        ->toBe(['unjudged']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('has the PHPUnit library installed wherever the PHPUnit runner contracts run', function (): void {
    expect(isPhpUnitLibraryInstalled())->toBeTrue();
})->skip(getenv('RUNNER_CONTRACTS') !== 'phpunit', 'only the PHPUnit runner contracts job installs its library');
