<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Number\IncrementInteger;

// A mutant on a line php-code-coverage leaves out of its map, which Pest calls
// uncovered, judged by the tests that read the value it changes, each run
// through Pest's own override (ADR-0004, decision 8). Pest's runs are real.

/** Each mutant of the fixture's unexecutable values under the whole suite, by where it is: its status and reason. */
const UNEXECUTABLE_JUDGED = [
    'src/Unexecutable/Rates.php:14' => 'killed',
    'src/Unexecutable/Rates.php:16' => 'killed',
    'src/Unexecutable/Rates.php:18' => 'unjudged no test reaches this value',
    'src/Unexecutable/Rates.php:20' => 'killed',
    'src/Unexecutable/Rates.php:22' => 'killed',
    'src/Unexecutable/BaseRate.php:9' => 'killed',
    'src/Unexecutable/Rated.php:9' => 'killed',
    'src/Unexecutable/Level.php:10' => 'killed',
    'src/Unexecutable/Level.php:11' => 'killed',
    'src/Unexecutable/Other.php:10' => 'killed',
    'src/Unexecutable/functions.php:10' => 'uncovered',
    'src/Unexecutable/functions.php:13' => 'unjudged loaded before the override',
    'src/Unexecutable/helpers.php:8' => 'killed',
    'src/Unexecutable/Scaled.php:13' => 'killed',
    'src/Unexecutable/Weighed.php:10' => 'killed',
    'src/Unexecutable/Weighed.php:15' => 'killed',
    'src/Unexecutable/Limits.php:10' => 'killed',
    'src/Unexecutable/Crowded.php:10'
        => 'unjudged ambiguous reference; src/Unexecutable/Crowded.php is covered by 11 test files',
    'src/Unexecutable/Crowded.php:14' => 'killed',
    'src/Unexecutable/Chain.php:10' => 'killed',
    'src/Unexecutable/Guarded.php:10' => 'killed',
    'src/Unexecutable/Early.php:10' => 'unjudged loaded before the override',
];

/**
 * Each mutant a library's runner reports for these files, tests and mutator, by
 * where it is: its status and reason.
 *
 * @return array<string, string>
 */
function unexecutableJudged(Library $library, Paths $files, WholeSuite|Group $tests, string $mutator): array
{
    $request = MutationRequest::of($files, $tests)->narrowedTo($files, Mutators::named($mutator));
    $result = $library->runner()->mutate($request);
    $judged = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : [] as $mutant) {
        $reason = $mutant->reason();
        $judged[sprintf('%s:%d', $mutant->location()->file()->value(), $mutant->location()->start()->number())]
            = trim(sprintf('%s %s', $mutant->status()->value, $reason instanceof Reason ? $reason->text() : ''));
    }

    return $judged;
}

/** Every file of the fixture's unexecutable values. */
function unexecutableFiles(): Paths
{
    $files = array_unique(array_map(
        static fn(string $mutant): string => explode(':', $mutant, 2)[0],
        array_keys(UNEXECUTABLE_JUDGED),
    ));

    return Paths::of(...array_map(static fn(string $file): Path => Path::of($file), array_values($files)));
}

it('judges each kind of value on a line that is not executable by the tests that read it', function (): void {
    $library = Library::pest(Patching::off());
    $judged = unexecutableJudged($library, unexecutableFiles(), WholeSuite::tests(), IncrementInteger::class);

    expect($judged)->toEqualCanonicalizing(UNEXECUTABLE_JUDGED);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('judges the same through a project root that is a symbolic link', function (): void {
    $root = sprintf('%s/library', Scratch::directory());
    symlink(Tree::at(Library::DIRECTORY), $root);

    $library = Library::pestAt($root, Patching::off());
    $judged = unexecutableJudged($library, unexecutableFiles(), WholeSuite::tests(), IncrementInteger::class);

    expect($judged)->toEqualCanonicalizing(UNEXECUTABLE_JUDGED);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('judges a held unit by its holding group alone, where a test outside it would kill', function (): void {
    $files = Paths::of(Path::of('src/Unexecutable/Guarded.php'));
    $held = Group::named('holds:src/Unexecutable/Guarded.php');

    $judged = unexecutableJudged(Library::pest(Patching::off()), $files, $held, IncrementInteger::class);

    expect($judged)->toBe(['src/Unexecutable/Guarded.php:10' => 'survived']);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('makes no mutant of a value outside a function, only of a parameter\'s default, with Infection', function (): void {
    $files = Paths::of(
        Path::of('src/Unexecutable/Values.php'),
        Path::of('src/Unexecutable/Weight.php'),
        Path::of('src/Unexecutable/Tier.php'),
    );

    $library = Library::infection(Seconds::of(10.0));
    $judged = unexecutableJudged($library, $files, WholeSuite::tests(), 'IncrementInteger');

    expect($judged)->toBe(['src/Unexecutable/Values.php:20' => 'killed']);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');
