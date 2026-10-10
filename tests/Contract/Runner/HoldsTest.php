<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Logical\TrueToFalse;
use Symfony\Component\Process\Process;

// Which tests Pest runs for a holds: group, over every shape a test can hold a
// path in (ADR-0004, decision 7): serially, under --parallel, and in a
// mutant's own process. Each test in the fixture that runs src/Shapes.php
// appends its label to the file LIBRARY_TRACE names, with where it ran. The
// skipped test and the test that covers the line without holding it never do.

/** Every test that holds src/Shapes.php and runs, by the label it traces. */
const HELD_SHAPES = [
    'it function', 'it fn', 'test function', 'test fn', 'arch function', 'arch fn', 'constant', 'twice',
    'row 1', 'row 2', 'row 3', 'describe', 'nested describe', 'higher order', 'phpunit class',
];

/**
 * Each label, as a test that ran in the suite's own run or a mutant's traces it.
 *
 * @param list<string> $labels
 * @return list<string>
 */
function shapesTraced(string $where, array $labels): array
{
    return array_map(static fn(string $label): string => sprintf('%s %s', $where, $label), $labels);
}

/**
 * The lines the fixture's tests traced into a file, none where none ran.
 *
 * @return list<string>
 */
function shapesLines(string $trace): array
{
    $lines = is_file($trace) ? file($trace, FILE_IGNORE_NEW_LINES) : false;

    return $lines === false ? [] : $lines;
}

/**
 * Runs the fixture's Pest over a holds: group with these arguments, as the
 * gate narrows a run to one, and answers its exit code and what the tests it
 * ran traced.
 *
 * @return array{int, list<string>}
 */
function shapesRun(string ...$arguments): array
{
    $trace = sprintf('%s/trace', Scratch::directory());
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/pest', ...$arguments, PhpUnitOption::DoNotFailOnEmptyTestSuite->value],
        Tree::at(Library::DIRECTORY),
        ['LIBRARY_TRACE' => $trace, 'PARATEST' => false, 'TEST_TOKEN' => false, 'UNIQUE_TEST_TOKEN' => false],
    );

    return [$process->run(), shapesLines($trace)];
}

it('selects every test that holds a path, and no other, when it runs the group', function (): void {
    [$exit, $traced] = shapesRun('--group=holds:src/Shapes.php');

    expect($exit)->toBe(0)->and($traced)->toEqualCanonicalizing(shapesTraced('suite', HELD_SHAPES));
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('selects the same tests when it runs the group in parallel', function (): void {
    [$exit, $traced] = shapesRun('--group=holds:src/Shapes.php', '--parallel', '--processes=3');

    expect($exit)->toBe(0)->and($traced)->toEqualCanonicalizing(shapesTraced('suite', HELD_SHAPES));
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('puts a test with two #[Holds] in both groups', function (): void {
    [$exit, $traced] = shapesRun('--group=holds:src/Legacy.php');

    expect($exit)->toBe(0)->and($traced)->toBe(['suite twice']);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('lists every holds: group among the suite\'s groups, one only #[Holds] on a closure names too', function (): void {
    $groups = Library::pest(Patching::off())->runner()->groups(Withheld::standard());
    $listed = static fn(string $group): bool => $groups instanceof Groups && $groups->has(Group::named($group));

    expect($listed('holds:src/Shapes.php'))->toBeTrue()
        ->and($listed('holds:src/Held.php'))->toBeTrue()
        ->and($listed('holds:src/Legacy.php'))->toBeTrue();
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('runs every test that holds a path, and no other, in a mutant\'s own process', function (): void {
    $trace = sprintf('%s/trace', Scratch::directory());
    $request = MutationRequest::of(Paths::of(Path::of('src/Shapes.php')), Group::named('holds:src/Shapes.php'))
        ->narrowedTo(Paths::of(Path::of('src/Shapes.php')), Narrowing::none()->toMutators(Mutators::named(TrueToFalse::class)));

    // Symfony's Process passes on only the variables PHP also has in $_SERVER.
    putenv(sprintf('LIBRARY_TRACE=%s', $trace));
    $_SERVER['LIBRARY_TRACE'] = $trace;

    try {
        $result = Library::pest(Patching::off())->runner()->mutate($request);
    } finally {
        putenv('LIBRARY_TRACE');
        unset($_SERVER['LIBRARY_TRACE']);
    }

    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];
    $traced = shapesLines($trace);
    $inMutant = array_values(array_filter($traced, static fn(string $line): bool => str_starts_with($line, 'mutant ')));

    expect(array_map(static fn(Mutant $mutant): string => $mutant->status()->value, $mutants))->toBe(['survived'])
        ->and($inMutant)->toEqualCanonicalizing(shapesTraced('mutant', HELD_SHAPES));
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');
