<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridges as PestBridges;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell as PestShell;
use NightWorksIO\MutationGate\Adapter\Pest\Project as PestProject;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Mutators\PinnedIdentical;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Equality\SmallerToSmallerOrEqual;
use Pest\Mutate\Mutators\Number\IncrementInteger;

$holds = [
    'holds:src/Adapter/Pest',
];

// The runs that vouch for a kill on the unmutated code serve the file the
// mutant changes through the same override as the mutant's own run. Only
// patched Pest runs them: its trial of a mutant on a line that is not
// executable runs its tests on their own first, and a narrowed kill stands
// only where its own run, replayed unmutated in its order up to its last
// killer, passes. Infection and phpunit run no such run, so nothing in them
// stands in for one.
//
// Linked.php, a class Composer autoloads, holds three mutants: LOOKS raised
// to 2, on a line that is not executable, and the loop's < made <=, both of
// which leave the link found once as before; and the loop's start raised to
// 1, which never looks. looks.php, a file of functions its spec requires,
// holds the same three, its default number of looks on a line that is not
// executable. Compared.php holds one, its constant-time comparison made `===`
// by a mutator pinned by source, which only a test that reads the file kills.

/**
 * What a patched Pest run of a file under src, judged by this spec, finds of
 * each mutant: its line, its mutator's short name, its status, its reason and
 * the tests that killed it, so a status that differs says why.
 *
 * @return list<array{int, string, MutantStatus, string, list<string>}>
 */
function overrideJudged(string $spec, string $file = 'Linked.php'): array
{
    return overrideJudgedBy(
        Library::pest(Patching::on(Library::canary()))->runner(),
        $spec,
        $file,
        Mutators::named(IncrementInteger::class, SmallerToSmallerOrEqual::class),
    );
}

/**
 * What a runner finds of each mutant these mutators make of a file under src,
 * judged by this spec, as overrideJudged() reads it.
 *
 * @return list<array{int, string, MutantStatus, string, list<string>}>
 */
function overrideJudgedBy(Runner $runner, string $spec, string $file, Mutators $mutators): array
{
    $mutated = sprintf('src/%s', $file);
    $source = Tree::at(sprintf('%s/%s', Library::DIRECTORY, $mutated));
    $test = Tree::at(sprintf('%s/tests/%s', Library::DIRECTORY, $spec));
    copy(Tree::at(sprintf('tests/Contract/Runner/override/%s', $mutated)), $source);
    copy(Tree::at(sprintf('tests/Contract/Runner/override/tests/%s', $spec)), $test);
    Patch::applyIn(Library::vendor());

    try {
        $result = $runner->mutate(
            MutationRequest::of(Paths::of(Path::of($mutated)), WholeSuite::tests())->narrowedTo(
                Paths::of(Path::of($mutated)),
                Narrowing::none()->toMutators($mutators),
            ),
        );
    } finally {
        unlink($source);
        unlink($test);
    }

    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];
    $judged = array_map(static fn(Mutant $mutant): array => [
        $mutant->location()->start()->number(),
        str_contains($mutant->mutation()->mutator(), '\\')
            ? substr((string) strrchr($mutant->mutation()->mutator(), '\\'), 1)
            : $mutant->mutation()->mutator(),
        $mutant->status(),
        $mutant->reason() instanceof Reason ? $mutant->reason()->text() : '',
        array_map(static fn(TestId $killer): string => $killer->value(), [...$mutant->killers()]),
    ], $mutants);
    usort($judged, static fn(array $one, array $other): int => [$one[0], $one[1]] <=> [$other[0], $other[1]]);

    return $judged;
}

it('kills and spares the mutants of a file whose tests read alike through the override, as a run with no control would', function (): void {
    expect(overrideJudged('LinkedSpec.php'))->toBe([
        [10, 'IncrementInteger', MutantStatus::Survived, '', []],
        [16, 'IncrementInteger', MutantStatus::Killed, '', ['P\\Tests\\LinkedSpec::__pest_evaluable_it_tells_a_link_for_a_link']],
        [16, 'SmallerToSmallerOrEqual', MutantStatus::Survived, '', []],
    ]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('kills no mutant of a file Composer does not autoload whose tests fail whenever the override serves a file, leaving each unjudged, with Pest', function (): void {
    $judged = overrideJudged('WrappedSpec.php', 'looks.php');
    $failed = ReplayVerdict::Failed->reason();

    expect(array_map(static fn(array $each): array => array_slice($each, 0, 3), $judged))->toBe([
        [10, 'IncrementInteger', MutantStatus::Unjudged],
        [14, 'IncrementInteger', MutantStatus::Unjudged],
        [14, 'SmallerToSmallerOrEqual', MutantStatus::Unjudged],
    ])
        ->and($judged[0][3])->toStartWith('the selected tests fail on their own (')
        ->and([$judged[1][3], $judged[2][3]])->each->toBe($failed instanceof Reason ? $failed->text() : '')
        ->and(array_column($judged, 4))->toBe([[], [], []]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('serves a class file Composer autoloads with no wrapper, its __FILE__ and __DIR__ the original\'s, with Pest', function (): void {
    expect(overrideJudged('ServedSpec.php'))->toBe([
        [10, 'IncrementInteger', MutantStatus::Survived, '', []],
        [16, 'IncrementInteger', MutantStatus::Killed, '', [
            'P\\Tests\\ServedSpec::__pest_evaluable_it_tells_a_link_for_a_link__from_the_file_it_is_declared_in__reading_a_file_through_PHP_s_own_wrapper',
        ]],
        [16, 'SmallerToSmallerOrEqual', MutantStatus::Survived, '', []],
    ]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('judges no mutant of a class file Composer autoloads whose test requires it by its path, which runs the original, with Pest', function (): void {
    $preloaded = Reason::preloaded(Path::of('src/Linked.php'))->text();

    expect(overrideJudged('BypassedSpec.php'))->toBe([
        [10, 'IncrementInteger', MutantStatus::Unjudged, 'loaded before the override (exit code 0; ran tests/BypassedSpec.php)', []],
        [16, 'IncrementInteger', MutantStatus::Unjudged, $preloaded, []],
        [16, 'SmallerToSmallerOrEqual', MutantStatus::Unjudged, $preloaded, []],
    ]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('kills a mutant pinned by source by the test that reads its file, which the wrapper serves it to, with Pest', function (): void {
    $root = Tree::at(Library::DIRECTORY);
    $project = PestProject::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    $pest = new Pest(
        $project,
        new PestShell(new LocalProcesses(new SystemClock()), $root),
        Patching::on(Library::canary()),
        new CapDirectory(),
        Triage::standard()->bounds(),
        bridges: PestBridges::to(Enabled::of(MutatorSet::of(PinnedIdentical::class))),
    );

    expect(overrideJudgedBy($pest, 'ComparedSpec.php', 'Compared.php', Mutators::named('contract/PinnedIdentical')))->toBe([
        [12, 'contract/PinnedIdentical', MutantStatus::Killed, '', ['P\\Tests\\ComparedSpec::__pest_evaluable_it_compares_a_token_in_constant_time__as_its_file_says']],
    ]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);
