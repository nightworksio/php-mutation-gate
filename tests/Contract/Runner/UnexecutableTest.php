<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TimeoutTriage;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Number\DecrementInteger;
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
 * where it is: its status and reason, less what a run that judged nothing
 * said of itself in brackets.
 *
 * @return array<string, string>
 */
function unexecutableJudged(Library $library, Paths $files, WholeSuite|Group $tests, string $mutator): array
{
    return array_map(
        static fn(string $judged): string => (string) preg_replace('/ \(.*\)$/s', '', $judged),
        unexecutableSaid($library, $files, $tests, $mutator),
    );
}

/**
 * Each mutant a library's runner reports for these files, tests and mutator, by
 * where it is: its status and its whole reason.
 *
 * @return array<string, string>
 */
function unexecutableSaid(Library $library, Paths $files, WholeSuite|Group $tests, string $mutator): array
{
    $request = MutationRequest::of($files, $tests)->narrowedTo($files, Narrowing::none()->toMutators(Mutators::named($mutator)));
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

it('names the tests that killed a mutant on a line that is not executable, as the coverage map names them', function (): void {
    $files = Paths::of(Path::of('src/Unexecutable/Rates.php'));
    $request = MutationRequest::of($files, WholeSuite::tests())->narrowedTo($files, Narrowing::none()->toMutators(Mutators::named(IncrementInteger::class)));
    $result = Library::pest(Patching::off())->runner()->mutate($request);
    $killers = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : [] as $mutant) {
        $killers += $mutant->location()->start()->number() === 14
            ? ['line 14' => array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()])]
            : [];
    }

    expect($killers)->toBe(['line 14' => ['P\Tests\UnexecutableSpec::__pest_evaluable_it_reads_a_constant_through_an_alias']]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('says what a run that judged nothing did: its exit code, any test that failed, and the files it ran', function (): void {
    $files = Paths::of(Path::of('src/Unexecutable/Early.php'));

    $said = unexecutableSaid(Library::pest(Patching::off()), $files, WholeSuite::tests(), IncrementInteger::class);

    expect($said['src/Unexecutable/Early.php:10'] ?? '')
        ->toMatch('/^unjudged loaded before the override \(exit code \d+; (first failing test .+; )?ran tests\/.+\.php.*\)$/');
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

/**
 * The trial of the mutant that makes the fixture's loop step nought, under a
 * library: its status, whether its tests' own time unmutated was recorded
 * under its limit's own multiple, and what timeout triage makes of it.
 *
 * @return array{MutantStatus, bool, MutantJudgement}|list<never>
 */
function unexecutablePaced(Library $library): array
{
    $files = Paths::of(Path::of('src/Unexecutable/Paced.php'));
    $request = MutationRequest::of($files, WholeSuite::tests())
        ->narrowedTo($files, Narrowing::none()->toMutators(Mutators::named(DecrementInteger::class)));
    $result = $library->runner()->mutate($request);
    $paced = array_values(array_filter(
        $result instanceof MutationResult ? [...$result->mutants()] : [],
        static fn(Mutant $mutant): bool => $mutant->location()->start()->number() === 10,
    ));
    $need = $paced === [] ? Unmeasured::duration() : $paced[0]->unmutatedNeed();
    $limit = $paced === [] ? Unmeasured::duration() : $paced[0]->limit();

    return $paced === [] ? [] : [
        $paced[0]->status(),
        $need instanceof Seconds && $limit instanceof Seconds && $need->seconds() * 3 < $limit->seconds(),
        TimeoutTriage::under(TimeoutMode::Confirm)->judged($paced[0]),
    ];
}

// The step is a constant, which coverage cannot see run, so its mutant runs
// as a trial. Its test passes on their own in a fraction of a second, and
// with the step made nought it never ends: the trial records the time its
// test took on its own, and timeout triage kills it by that time.
it('records the time a trial\'s tests took on their own, unmutated, so a trial that hangs is killed by timeout', function (): void {
    $library = Library::pestWithin(Patching::off(), LimitBounds::between(Seconds::of(1.0), Seconds::of(300.0)), 'pest unpatched, paced');

    expect(unexecutablePaced($library))->toBe([MutantStatus::TimedOut, true, MutantJudgement::KilledByTimeout]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('records the time a trial\'s tests took on their own, unmutated, with patched Pest', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pestWithin(
        Patching::on(Library::canary()),
        LimitBounds::between(Seconds::of(1.0), Seconds::of(300.0)),
        'pest patched, paced',
    );

    expect(unexecutablePaced($library))->toBe([MutantStatus::TimedOut, true, MutantJudgement::KilledByTimeout]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');
