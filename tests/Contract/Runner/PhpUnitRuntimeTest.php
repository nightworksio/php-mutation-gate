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
use NightWorksIO\MutationGate\Core\Mutant\Reason;
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
use NightWorksIO\MutationGate\Tests\Support\Scratch;
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
 * the library's tests, in the order given, by a PHPUnit that inherits these
 * variables besides the gate's.
 *
 * @param list<string>          $covering
 * @param array<string, string> $inherited
 */
function judgedByPhpUnit(
    Mutator $mutator,
    string $file,
    int $nth,
    array $covering,
    float $limit = 30.0,
    array $inherited = [],
): Mutant|CannotJudge {
    $project = Project::at(Tree::at(PHPUNIT_LIBRARY), Path::of('vendor'), Path::of('.mutation-gate'));
    $override = Override::writtenFor($project);
    $made = Engine::with($mutator)->mutantsOf(
        Path::of($file),
        Contents::of((string) file_get_contents($project->absolute(Path::of($file)))),
    );

    if ($override instanceof CannotJudge || ! $made instanceof MadeMutants) {
        return CannotJudge::because('The library cannot be mutated.');
    }

    $shell = new ProcessShell($project->root(), [...getenv(), ...$inherited]);

    return new MutantRun($project, $shell, new Invocation($project, $override))->judged(
        [...$made][$nth],
        TestIds::of(...array_map(static fn(string $test): TestId => TestId::of(sprintf('Tests\%s', $test)), $covering)),
        MutationRequest::of(Paths::of(Path::of($file)), WholeSuite::tests()),
        Seconds::of($limit),
    );
}

/** @return list<string> a mutant's status and the tests that killed it, or why it is unjudged */
function verdictOf(Mutant|CannotJudge $mutant): array
{
    return match (true) {
        $mutant instanceof CannotJudge => [$mutant->why()],
        $mutant->reason() instanceof Reason => [$mutant->status()->value, $mutant->reason()->text()],
        default => [
            $mutant->status()->value,
            ...array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]),
        ],
    };
}

it('drives a PHPUnit the runner supports', function (): void {
    expect(Installed::versionsIn(Tree::at(sprintf('%s/vendor/composer/installed.json', PHPUNIT_LIBRARY))))
        ->toBeInstanceOf(Versions::class);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('serves a mutant of a class to the test that kills it, and names that test', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 0, ['MoneySpec::addsTwoAmounts'])))
        ->toBe(['killed', 'Tests\MoneySpec::addsTwoAmounts']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('serves a mutant of a function Composer loads before anything else', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/helpers.php', 0, ['MoneySpec::doublesThroughAFunctionComposerLoads'])))
        ->toBe(['killed', 'Tests\MoneySpec::doublesThroughAFunctionComposerLoads']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('lets a mutant survive the test that runs it and checks nothing of it', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 1, ['MoneySpec::countsWithoutSayingSo'])))
        ->toBe(['survived']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('passes a test\'s own locking, truncating, touching and listing of files while it stands in for file://', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 1, ['FilesSpec::locksTruncatesTouchesAndListsFiles'])))
        ->toBe(['survived']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('stops a mutant that never ends at its limit', function (): void {
    expect(verdictOf(judgedByPhpUnit(new DecrementToIncrement(), 'src/Money.php', 0, ['MoneySpec::drainsAnAmountToNothing'], 3.0)))
        ->toBe(['timed-out']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('leaves unjudged a mutant whose tests\' ids match no test', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 0, ['MoneySpec::noSuchTest'])))
        ->toBe(['unjudged', 'PHPUnit ran none of the 1 tests that cover it: no id matched a test.']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('judges a mutant by the test that fails, however many risky tests run before it', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 0, ['AaRiskySpec::addsAndChecksNothing', 'MoneySpec::addsTwoAmounts'])))
        ->toBe(['killed', 'Tests\MoneySpec::addsTwoAmounts']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('serves the mutant to a test PHPUnit runs in a process of its own, and names that test', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 0, ['IsolatedSpec::addsInAProcessOfItsOwn'])))
        ->toBe(['killed', 'Tests\IsolatedSpec::addsInAProcessOfItsOwn']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('kills a mutant whose fatal error ends the test running it, and names that test', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 2, ['QuirksSpec::padsWithinMemory'])))
        ->toBe(['killed', 'Tests\QuirksSpec::padsWithinMemory']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('lets a mutant survive a test that reads a file\'s lines to its end, touches a file and states a dangling link', function (string $test): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 1, [$test])))->toBe(['survived']);
})->with(['QuirksSpec::readsEveryLineToTheEnd', 'QuirksSpec::touchesAFileAndStatesADanglingLink'])
    ->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('leaves unjudged a mutant whose every test is set aside, however PHPUnit sets it aside', function (string $test): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 1, [$test])))
        ->toBe(['unjudged', 'PHPUnit skipped, or marked incomplete, every test that covers it.']);
})->with([
    'skipped in its body' => ['QuirksSpec::skipsItself'],
    'skipped in setUp' => ['SkippedInSetUpSpec::countsWhereItCan'],
    'marked incomplete in setUp' => ['IncompleteInSetUpSpec::countsWhereItCan'],
    'skipped with its whole class' => ['SkippedBeforeClassSpec::countsWhereItCan'],
    'skipped for a requirement it does not meet' => ['QuirksSpec::needsAnExtensionNoPhpHas'],
])->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('lets a mutant survive the tests that run beside one set aside in setUp', function (string $setAside): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 1, [$setAside, 'MoneySpec::countsWithoutSayingSo'])))
        ->toBe(['survived']);
})->with(['SkippedInSetUpSpec::countsWhereItCan', 'IncompleteInSetUpSpec::countsWhereItCan'])
    ->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('leaves unjudged, with what PHPUnit said, a mutant whose run PHPUnit fails for a warning the project fails on', function (): void {
    $verdict = verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 1, ['QuirksSpec::warns']));

    expect($verdict[0])->toBe('unjudged')
        ->and($verdict[1] ?? '')->toStartWith('PHPUnit failed the run, though no test that ran failed. PHPUnit said:')
        ->and($verdict[1] ?? '')->toContain('a project warns');
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('leaves unjudged a mutant a test puts PHP\'s own file wrapper back before it loads', function (): void {
    expect(verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 0, ['QuirksSpec::putsBackPhpsOwnFileWrapperFirst'])))->toBe([
        'unjudged',
        'The mutated file never ran in its place: PHPUnit loaded the file some other way, such as another wrapper.',
    ]);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('serves each mutant, and no later run a mutant, where the project keeps an opcache file cache', function (): void {
    $settings = sprintf('%s/ini', Scratch::directory());
    mkdir($settings);
    file_put_contents(sprintf('%s/opcache.ini', $settings), sprintf(
        "opcache.enable=1\nopcache.enable_cli=1\nopcache.file_cache=%s\nopcache.file_cache_only=1\nopcache.validate_timestamps=0\n",
        Scratch::directory(),
    ));
    $cached = ['PHP_INI_SCAN_DIR' => sprintf('%s%s', PATH_SEPARATOR, $settings)];
    $killed = verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 0, ['MoneySpec::addsTwoAmounts'], inherited: $cached));
    $after = verdictOf(judgedByPhpUnit(new PlusToMinus(), 'src/Money.php', 1, ['MoneySpec::addsTwoAmounts'], inherited: $cached));
    Scratch::sweep();

    expect(extension_loaded('Zend OPcache'))->toBeTrue('a PHP without opcache proves nothing of it')
        ->and($killed)->toBe(['killed', 'Tests\MoneySpec::addsTwoAmounts'])
        ->and($after)->toBe(['survived']);
})->skip(! isPhpUnitLibraryInstalled(), 'the runner contracts job installs the PHPUnit library');

it('has the PHPUnit library installed wherever the PHPUnit runner contracts run', function (): void {
    expect(isPhpUnitLibraryInstalled())->toBeTrue();
})->skip(getenv('RUNNER_CONTRACTS') !== 'phpunit', 'only the PHPUnit runner contracts job installs its library');
