<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\MergedUnits;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/** The mutant on a line of a file, with this status. */
function mergedUnitMutant(string $file, int $line, MutantStatus $status): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), 'Minus', '- a + b', $line),
        sprintf('%s-%d', $file, $line),
        Location::of(Path::of($file), Line::of($line), Line::of($line)),
        Mutation::of('Minus', MutatorFamily::Arithmetic, '- a + b'),
        $status,
        Unmeasured::duration(),
    );
}

/** Shard `number` of a plan that cuts the test files into `parts`, taking every one of these units. */
function mergedUnitShard(int $number, int $parts, string ...$files): Shard
{
    return Shard::of(
        ShardId::of($number),
        Package::at(Path::root()),
        Units::of(...array_map(static fn(string $file): Unit => Unit::file(Path::of($file)), $files)),
        Seconds::of(1.0),
        sprintf('src, part %d of %d', $number, $parts),
    )->inPart(TestPart::of($number, $parts));
}

/**
 * A shard, with its result of these mutants.
 *
 * @return array{Shard, ShardResult, MutationResult}
 */
function mergedUnitRead(Shard $shard, Mutants $mutants, Units $unjudged, MutantIds $flaky): array
{
    $mutated = MutationResult::skippingNone($mutants);
    $result = ShardResult::of(
        Digest::sha256Of('plan'),
        $shard->id(),
        Keys::none(),
        $mutated,
        Measurement::of(Seconds::of(1.0), 'pest', Moment::at('2026-11-08T12:00:00Z')),
    )->withUnjudged($unjudged)->withFlaky($flaky);

    return [$shard, $result, $mutated];
}

/**
 * Each unit as its path and each mutant's line and status.
 *
 * @return list<string>|string
 */
function mergedUnitsDescribed(UnitResults|CannotJudge $units): array|string
{
    return $units instanceof CannotJudge ? $units->why() : array_map(
        static fn(UnitResult $result): string => sprintf('%s: %s', $result->unit()->path()->value(), implode(', ', array_map(
            static fn(Mutant $mutant): string => sprintf('%d %s', $mutant->location()->start()->number(), $mutant->status()->value),
            [...$result->mutants()],
        ))),
        [...$units],
    );
}

$plan = static fn(Shard ...$shards): Plan => Plan::of(Revision::ref('HEAD'), Digest::sha256Of('base'), Keys::none(), Shards::of(...$shards));

it('merges each unit\'s mutants across the shards that each judge it by a part of the test files', function () use ($plan): void {
    $one = mergedUnitShard(1, 2, 'src/A.php', 'src/B.php');
    $two = mergedUnitShard(2, 2, 'src/A.php', 'src/B.php');

    expect(mergedUnitsDescribed(MergedUnits::of($plan($one, $two), [
        mergedUnitRead($one, Mutants::of(
            mergedUnitMutant('src/A.php', 3, MutantStatus::Survived),
            mergedUnitMutant('src/A.php', 4, MutantStatus::NotReached),
            mergedUnitMutant('src/B.php', 5, MutantStatus::Uncovered),
        ), Units::none(), MutantIds::none()),
        mergedUnitRead($two, Mutants::of(
            mergedUnitMutant('src/A.php', 3, MutantStatus::Killed),
            mergedUnitMutant('src/A.php', 4, MutantStatus::Survived),
            mergedUnitMutant('src/B.php', 5, MutantStatus::Uncovered),
        ), Units::none(), MutantIds::none()),
    ])))->toBe(['src/A.php: 3 killed, 4 survived', 'src/B.php: 5 uncovered']);
});

it('runs no unit a shard that holds it left no result for, or ran out of time before', function () use ($plan): void {
    $one = mergedUnitShard(1, 2, 'src/A.php', 'src/B.php');
    $two = mergedUnitShard(2, 2, 'src/A.php', 'src/B.php');
    $mutants = Mutants::of(mergedUnitMutant('src/A.php', 3, MutantStatus::Killed), mergedUnitMutant('src/B.php', 5, MutantStatus::Survived));

    expect(mergedUnitsDescribed(MergedUnits::of($plan($one, $two), [mergedUnitRead($one, $mutants, Units::none(), MutantIds::none())])))->toBe([])
        ->and(mergedUnitsDescribed(MergedUnits::of($plan($one, $two), [
            mergedUnitRead($one, $mutants, Units::none(), MutantIds::none()),
            mergedUnitRead($two, $mutants, Units::of(Unit::file(Path::of('src/B.php'))), MutantIds::none()),
        ])))->toBe(['src/A.php: 3 killed']);
});

it('gives a merged unit the mutants any of its shards found flaky', function () use ($plan): void {
    $one = mergedUnitShard(1, 2, 'src/A.php');
    $two = mergedUnitShard(2, 2, 'src/A.php');
    $first = mergedUnitMutant('src/A.php', 3, MutantStatus::Survived);
    $second = mergedUnitMutant('src/A.php', 4, MutantStatus::Survived);
    $units = MergedUnits::of($plan($one, $two), [
        mergedUnitRead($one, Mutants::of($first, $second), Units::none(), MutantIds::of($first->id())),
        mergedUnitRead($two, Mutants::of($first, $second), Units::none(), MutantIds::of($second->id())),
    ]);

    expect($units instanceof UnitResults ? [...$units][0]->flaky() : $units)->toEqual(MutantIds::of($first->id(), $second->id()));
});

it('keeps each shard\'s own units, in the order the shards take them, where every unit is one shard\'s', function () use ($plan): void {
    $one = Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Unit::file(Path::of('src/B.php'))), Seconds::of(1.0), 'b');
    $two = Shard::of(ShardId::of(2), Package::at(Path::root()), Units::of(Unit::file(Path::of('src/A.php'))), Seconds::of(1.0), 'a');

    expect(mergedUnitsDescribed(MergedUnits::of($plan($one, $two), [
        mergedUnitRead($one, Mutants::of(mergedUnitMutant('src/B.php', 5, MutantStatus::Survived)), Units::none(), MutantIds::none()),
        mergedUnitRead($two, Mutants::of(mergedUnitMutant('src/A.php', 3, MutantStatus::Killed)), Units::none(), MutantIds::none()),
    ])))->toBe(['src/B.php: 5 survived', 'src/A.php: 3 killed']);
});

it('cannot judge a unit whose shards hold other mutants of it, or a mutant none of them reached', function () use ($plan): void {
    $one = mergedUnitShard(1, 2, 'src/A.php');
    $two = mergedUnitShard(2, 2, 'src/A.php');

    expect(MergedUnits::of($plan($one, $two), [
        mergedUnitRead($one, Mutants::of(mergedUnitMutant('src/A.php', 3, MutantStatus::Survived)), Units::none(), MutantIds::none()),
        mergedUnitRead($two, Mutants::of(mergedUnitMutant('src/A.php', 4, MutantStatus::Survived)), Units::none(), MutantIds::none()),
    ]))->toBeInstanceOf(CannotJudge::class)
        ->and(MergedUnits::of($plan($one, $two), [
            mergedUnitRead($one, Mutants::of(mergedUnitMutant('src/A.php', 3, MutantStatus::NotReached)), Units::none(), MutantIds::none()),
            mergedUnitRead($two, Mutants::of(mergedUnitMutant('src/A.php', 3, MutantStatus::NotReached)), Units::none(), MutantIds::none()),
        ]))->toBeInstanceOf(CannotJudge::class);
});
