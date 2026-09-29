<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;

// What every runner answers over the fixture library: src/Money.php, whose
// mutants are killed, survived, uncovered and timed out, and src/Held.php, held
// by the group holds:src/Held.php. One line per implementation.

$runners = [
    'the fake' => fn(): Runner => RunnerFake::ofTheFixture(),
];

/** @return list<string> */
$statuses = static fn(Mutants $mutants): array => array_values(array_unique(array_map(static fn(Mutant $mutant): string => $mutant->status()->value, iterator_to_array($mutants, preserve_keys: true))));

/** @return list<string> */
$files = static fn(Mutants $mutants): array => array_values(array_unique(array_map(static fn(Mutant $mutant): string => $mutant->location()->file()->value(), iterator_to_array($mutants, preserve_keys: true))));

it('answers the same identity every time it is asked', function (Runner $runner): void {
    $identity = $runner->identity();

    expect($identity)->toBeInstanceOf(Identity::class)
        ->and($runner->identity())->toEqual($identity)
        ->and($identity instanceof Identity ? $identity->runner() : '')->not->toBe('');
})->with($runners);

it('lists the group that holds a path among the suite\'s groups', function (Runner $runner): void {
    $groups = $runner->groups();

    expect($groups instanceof Groups && $groups->has(Group::named('holds:src/Held.php')))->toBeTrue();
})->with($runners);

it('reports a killed, a survived, an uncovered and a timed-out mutant', function (Runner $runner) use ($statuses): void {
    $result = $runner->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));

    expect($result)->toBeInstanceOf(MutationResult::class)
        ->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(0)
        ->and($result instanceof MutationResult ? $statuses($result->mutants()) : [])->toEqualCanonicalizing([
            MutantStatus::Killed->value,
            MutantStatus::Survived->value,
            MutantStatus::Uncovered->value,
            MutantStatus::TimedOut->value,
        ]);
})->with($runners);

it('reports only the files it was asked for, less the paths left out', function (Runner $runner) use ($files): void {
    $everything = $runner->mutate(MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests()));
    $leftOut = $runner->mutate(MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())->leavingOut(Paths::of(Path::of('src/Held.php'))));

    expect($everything instanceof MutationResult ? $files($everything->mutants()) : [])->toEqualCanonicalizing(['src/Money.php', 'src/Held.php'])
        ->and($leftOut instanceof MutationResult ? $files($leftOut->mutants()) : [])->toBe(['src/Money.php']);
})->with($runners);

it('gives every mutant an id of its own in the gate\'s spelling', function (Runner $runner): void {
    $result = $runner->mutate(MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests()));
    $ids = $result instanceof MutationResult ? array_map(static fn(Mutant $mutant): string => $mutant->id()->value(), iterator_to_array($result->mutants(), preserve_keys: true)) : [];

    expect($ids)->not->toBe([])
        ->and(array_unique($ids))->toBe($ids)
        ->and(array_filter($ids, static fn(string $id): bool => ! MutantId::parse($id) instanceof MutantId))->toBe([]);
})->with($runners);

it('runs a mutant again and matches it back by the gate\'s id', function (Runner $runner): void {
    $ids = static fn(Mutants $mutants): array => array_map(static fn(Mutant $mutant): string => $mutant->id()->value(), iterator_to_array($mutants, preserve_keys: true));
    $result = $runner->mutate(MutationRequest::of(Paths::of(Path::of('src/Held.php')), Group::named('holds:src/Held.php')));

    if (! $result instanceof MutationResult) {
        expect($result)->toBeInstanceOf(MutationResult::class);

        return;
    }

    $mutants = $result->mutants();
    $retried = $runner->retry($mutants, Seconds::of(20.0));

    expect($ids($mutants))->not->toBe([])
        ->and($retried instanceof Mutants ? $ids($retried) : [])->toBe($ids($mutants));
})->with($runners);

it('names the test files that can judge a covered file, and none for a file nothing covers', function (Runner $runner): void {
    $map = $runner->coverage(CoverageRequest::running(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $covered = $map instanceof CoverageMap ? $runner->judges(Path::of('src/Money.php'), $map) : $map;
    $uncovered = $map instanceof CoverageMap ? $runner->judges(Path::of('src/Nowhere.php'), $map) : $map;

    expect($covered instanceof Paths ? $covered->count() : 0)->toBeGreaterThan(0)
        ->and($uncovered)->toEqual(Paths::none());
})->with($runners);
