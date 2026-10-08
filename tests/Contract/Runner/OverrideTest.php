<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;
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
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Equality\SmallerToSmallerOrEqual;
use Pest\Mutate\Mutators\Number\IncrementInteger;

// The runs that vouch for a kill on the unmutated code serve the file the
// mutant changes through the same override as the mutant's own run. Only
// patched Pest runs them: its trial of a mutant on a line that is not
// executable runs its tests on their own first, and a narrowed kill stands
// only where its own run, replayed unmutated in its order up to its last
// killer, passes. Infection and phpunit run no such run, so nothing in them
// stands in for one.
//
// Linked.php holds three mutants: LOOKS raised to 2, on a line that is not
// executable, and the loop's < made <=, both of which leave the link found
// once as before; and the loop's start raised to 1, which never looks.

/**
 * What a patched Pest run of src/Linked.php, judged by this spec, finds of
 * each mutant: its line, its mutator's short name, its status, its reason and
 * the tests that killed it, so a status that differs says why.
 *
 * @return list<array{int, string, MutantStatus, string, list<string>}>
 */
function overrideJudged(string $spec): array
{
    $source = Tree::at(sprintf('%s/src/Linked.php', Library::DIRECTORY));
    $test = Tree::at(sprintf('%s/tests/%s', Library::DIRECTORY, $spec));
    copy(Tree::at('tests/Contract/Runner/override/src/Linked.php'), $source);
    copy(Tree::at(sprintf('tests/Contract/Runner/override/tests/%s', $spec)), $test);
    Patch::applyIn(Library::vendor());

    try {
        $result = Library::pest(Patching::on(Library::canary()))->runner()->mutate(
            MutationRequest::of(Paths::of(Path::of('src/Linked.php')), WholeSuite::tests())->narrowedTo(
                Paths::of(Path::of('src/Linked.php')),
                Narrowing::none()->toMutators(Mutators::named(IncrementInteger::class, SmallerToSmallerOrEqual::class)),
            ),
        );
    } finally {
        unlink($source);
        unlink($test);
    }

    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];
    $judged = array_map(static fn(Mutant $mutant): array => [
        $mutant->location()->start()->number(),
        substr((string) strrchr($mutant->mutation()->mutator(), '\\'), 1),
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
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('kills no mutant of a file whose tests fail whenever the override serves a file, leaving each unjudged, with Pest', function (): void {
    $judged = overrideJudged('WrappedSpec.php');

    expect(array_map(static fn(array $each): array => array_slice($each, 0, 3), $judged))->toBe([
        [10, 'IncrementInteger', MutantStatus::Unjudged],
        [16, 'IncrementInteger', MutantStatus::Unjudged],
        [16, 'SmallerToSmallerOrEqual', MutantStatus::Unjudged],
    ])
        ->and($judged[0][3])->toStartWith('the selected tests fail on their own (')
        ->and([$judged[1][3], $judged[2][3]])->each->toBe(ReplayVerdict::Failed->reason())
        ->and(array_column($judged, 4))->toBe([[], [], []]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');
