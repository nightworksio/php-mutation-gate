<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\LeftoverParts;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\LeftAtTheDeadline;
use NightWorksIO\MutationGate\Tests\Support\MeasuredFields;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Writes these shares of the money shard's left mutants, and a result for each share these numbers name,
 * holding these native ids.
 *
 * @param array<int, list<string>> $shares  each share's native ids, by its number
 * @param array<int, list<string>> $results each written result's native ids, by its share's number
 */
function leftoverShares(string $project, Plan $plan, ShardResult $whole, array $shares, array $results): void
{
    $cut = [];

    foreach ($shares as $number => $natives) {
        $cut[] = LeftoverShare::of(ShardId::of(1), $number, ...LeftAtTheDeadline::listed($whole, ...$natives));
    }

    Scratch::write($project, LeftoverParts::file(Workspace::results())->value(), LeftoverShares::cut($plan->digest(), ...$cut)->encode());

    foreach ($results as $number => $natives) {
        $held = static fn(Mutant $mutant): ?Mutant => in_array($mutant->nativeId(), $natives, strict: true) ? $mutant : null;
        Scratch::write(
            $project,
            LeftoverParts::resultOf(Workspace::results(), LeftoverShare::of(ShardId::of(1), $number))->value(),
            ShardResultFile::encode(LeftAtTheDeadline::resultOf($whole, $held)),
        );
    }
}

/** @return list<string> each mutant of the money unit, by its native id, with its status */
function leftoverStatuses(Results|CannotJudge $results): array
{
    if ($results instanceof CannotJudge) {
        return [$results->why()];
    }

    $money = array_values(array_filter(
        [...$results->units()],
        static fn(UnitResult $unit): bool => $unit->unit()->path()->value() === 'src/Money.php',
    ));

    return $money === [] ? [] : array_map(
        static fn(Mutant $mutant): string => sprintf('%s %s', $mutant->nativeId(), $mutant->status()->value),
        [...$money[0]->mutants()],
    );
}

it('judges the mutants a shard left at its deadline as the jobs of the second wave judged them, the same as one job judging every one', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    $stopped = leftoverStatuses(Results::read($plan, Workspace::results(), Directory::at($project)));
    leftoverShares($project, $plan, $whole, [1 => ['GreaterThan-16'], 2 => ['Minus-21']], [1 => ['GreaterThan-16'], 2 => ['Minus-21']]);
    $judged = leftoverStatuses(Results::read($plan, Workspace::results(), Directory::at($project)));
    expect($stopped)->toBe(['Plus-11 killed', 'GreaterThan-16 unjudged', 'Minus-21 unjudged', 'Decrement-27 timed-out'])
        ->and($judged)->toBe(array_map(
            static fn(Mutant $mutant): string => sprintf('%s %s', $mutant->nativeId(), $mutant->status()->value),
            LeftAtTheDeadline::mutantsOf($whole),
        ))
        ->and($judged)->not->toBe($stopped);
});

it('leaves the mutants of a share whose job left no result unjudged', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverShares($project, $plan, $whole, [1 => ['GreaterThan-16'], 2 => ['Minus-21']], [2 => ['Minus-21']]);

    expect(leftoverStatuses(Results::read($plan, Workspace::results(), Directory::at($project))))
        ->toBe(['Plus-11 killed', 'GreaterThan-16 unjudged', 'Minus-21 uncovered', 'Decrement-27 timed-out']);
});

it('cannot judge a share\'s result that holds other mutants than its share lists', function (array $held): void {
    /** @var list<string> $held */
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverShares($project, $plan, $whole, [1 => ['GreaterThan-16'], 2 => ['Minus-21']], [1 => $held, 2 => ['Minus-21']]);

    expect(leftoverStatuses(Results::read($plan, Workspace::results(), Directory::at($project))))->toBe([<<<'SAID'
        The result of share 1 of the mutants shard 1 left holds other mutants than the share lists,
        so it cannot stand for them.
        Judge each share's mutants alone, with the shares the run cut.
        SAID]);
})->with([
    'one more' => [['GreaterThan-16', 'Minus-21']],
    'another' => [['Plus-11']],
]);

it('leaves a mutant its share lists and its share\'s result lacks as the shard left it', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverShares($project, $plan, $whole, [1 => LeftAtTheDeadline::LEFT], [1 => ['Minus-21']]);

    expect(leftoverStatuses(Results::read($plan, Workspace::results(), Directory::at($project))))
        ->toBe(['Plus-11 killed', 'GreaterThan-16 unjudged', 'Minus-21 uncovered', 'Decrement-27 timed-out']);
});

it('cannot judge shares that list other mutants than the shard left, or one twice', function (array $shares): void {
    /** @var array<int, list<string>> $shares */
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverShares($project, $plan, $whole, $shares, $shares);

    expect(leftoverStatuses(Results::read($plan, Workspace::results(), Directory::at($project))))->toBe([<<<'SAID'
        The shares of the mutants shard 1 left list other mutants than it left, so they cannot stand for them.
        Cut the shares from the results the shards of this run left.
        SAID]);
})->with([
    'one short' => [[1 => ['GreaterThan-16']]],
    'one judged' => [[1 => ['GreaterThan-16'], 2 => ['Minus-21', 'Plus-11']]],
    'one twice' => [[1 => ['GreaterThan-16', 'Minus-21'], 2 => ['Minus-21']]],
]);

it('takes each result as its shard left it without shares, or with shares cut from another plan', function (bool $written): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    $share = LeftoverShare::of(ShardId::of(1), 1, ...LeftAtTheDeadline::listed($whole, ...LeftAtTheDeadline::LEFT));
    Scratch::write($project, LeftoverParts::resultOf(Workspace::results(), $share)->value(), ShardResultFile::encode($whole));

    if ($written) {
        Scratch::write(
            $project,
            LeftoverParts::file(Workspace::results())->value(),
            LeftoverShares::cut(Digest::sha256Of('another plan'), $share)->encode(),
        );
    }

    expect(leftoverStatuses(Results::read($plan, Workspace::results(), Directory::at($project))))
        ->toBe(['Plus-11 killed', 'GreaterThan-16 unjudged', 'Minus-21 unjudged', 'Decrement-27 timed-out']);
})->with(['no shares' => [false], 'another plan\'s' => [true]]);

it('cannot judge shares it cannot read', function (): void {
    [$project, $plan] = LeftAtTheDeadline::run();
    Scratch::write($project, LeftoverParts::file(Workspace::results())->value(), '{"format": 1, "plan": "a", "shares": [{"shard": 1, "share": 1, "mutants": [{"id": "x", "native": "n", "file": "f"}]}]}');

    expect(leftoverStatuses(Results::read($plan, Workspace::results(), Directory::at($project))))
        ->toBe(['The shares of the mutants the shards left cannot be read: the file.shares[0].mutants[0].id is not a mutant\'s id.']);
});

it('leaves its shares and each share\'s result in a directory of results', function (): void {
    $share = LeftoverShare::of(ShardId::of(7), 3);

    expect(LeftoverParts::file(Path::of('/r'))->value())->toBe('/r/leftovers.json')
        ->and(LeftoverParts::resultOf(Path::of('/r'), $share)->value())->toBe('/r/7.leftover-3.json');
});

it('counts each mutant a share\'s job found flaky as flaky in its unit', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverShares($project, $plan, $whole, [1 => LeftAtTheDeadline::LEFT], []);
    $flaky = LeftAtTheDeadline::ids($whole, 'Minus-21');
    $held = static fn(Mutant $mutant): ?Mutant => in_array($mutant->nativeId(), LeftAtTheDeadline::LEFT, strict: true) ? $mutant : null;
    $share = LeftoverShare::of(ShardId::of(1), 1);
    Scratch::write(
        $project,
        LeftoverParts::resultOf(Workspace::results(), $share)->value(),
        ShardResultFile::encode(LeftAtTheDeadline::resultOf($whole, $held)->withFlaky($flaky)),
    );
    $results = Results::read($plan, Workspace::results(), Directory::at($project));
    $money = $results instanceof Results ? array_values(array_filter(
        [...$results->units()],
        static fn(UnitResult $unit): bool => $unit->unit()->path()->value() === 'src/Money.php',
    )) : [];

    expect(array_map(static fn(MutantId $id): string => $id->value(), $money === [] ? [] : [...$money[0]->flaky()]))
        ->toBe(array_map(static fn(MutantId $id): string => $id->value(), [...$flaky]));
});

it('joins what a share\'s job warned of to its shard\'s warnings', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverShares($project, $plan, $whole, [1 => LeftAtTheDeadline::LEFT], []);
    $held = static fn(Mutant $mutant): ?Mutant => in_array($mutant->nativeId(), LeftAtTheDeadline::LEFT, strict: true) ? $mutant : null;
    Scratch::write(
        $project,
        LeftoverParts::resultOf(Workspace::results(), LeftoverShare::of(ShardId::of(1), 1))->value(),
        ShardResultFile::encode(LeftAtTheDeadline::resultOf($whole, $held)->withWarnings(Warnings::of(Warning::noting('The share\'s job warned.')))),
    );
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    expect($results instanceof Results ? array_map(static fn(Warning $warning): string => $warning->text(), [...$results->warnings()]) : $results)
        ->toContain('The share\'s job warned.');
});

it('carries what each share\'s job measured of its own time onto its shard\'s result', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverShares($project, $plan, $whole, [1 => LeftAtTheDeadline::LEFT], []);
    $held = static fn(Mutant $mutant): ?Mutant => in_array($mutant->nativeId(), LeftAtTheDeadline::LEFT, strict: true) ? $mutant : null;
    Scratch::write(
        $project,
        LeftoverParts::resultOf(Workspace::results(), LeftoverShare::of(ShardId::of(1), 1))->value(),
        MeasuredFields::added(ShardResultFile::encode(LeftAtTheDeadline::resultOf($whole, $held)), ['platform' => 'p', 'setup' => 40.0, 'passTwo' => 0.6]),
    );
    $results = Results::read($plan, Workspace::results(), Directory::at($project));
    $shares = [];

    foreach ($results instanceof Results ? $results->shards() : [] as [, $result]) {
        $shares = [...$shares, ...$result->measured()->shares()];
    }

    expect($shares)->toEqual([JobTimes::of('p', Seconds::of(40.0), NotGiven::value(), 0.6)]);
});
