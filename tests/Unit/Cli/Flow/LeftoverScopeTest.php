<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\LeftoverParts;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\ShardLeft;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\LeftAtTheDeadline;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * @param  iterable<Mutant> $mutants
 * @return list<string>      each mutant, by its native id, with its status
 */
function leftoverRunStatuses(iterable $mutants): array
{
    $statuses = [];

    foreach ($mutants as $mutant) {
        $statuses[] = sprintf('%s %s', $mutant->nativeId(), $mutant->status()->value);
    }

    return $statuses;
}

it('judges a share of the mutants a shard left in the shard\'s units that hold them, making them alone, as the shard judged them', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::runOf(Planned::oneShard());
    $share = LeftoverShare::of(ShardId::of(1), 1, ...LeftAtTheDeadline::listed($whole, ...LeftAtTheDeadline::LEFT));
    Scratch::write($project, LeftoverParts::file(Workspace::results())->value(), LeftoverShares::cut($plan->digest(), $share)->encode());
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->judging($share)
        ->run($plan, ShardId::of(1), Workspace::results());
    $left = ShardResultFile::decode((string) file_get_contents(sprintf('%s/%s', $project, LeftoverParts::resultOf(Workspace::results(), $share)->value())));
    $results = Results::read($plan, Workspace::results(), Directory::at($project));
    $money = $results instanceof Results ? array_values(array_filter(
        [...$results->units()],
        static fn(UnitResult $unit): bool => $unit->unit()->path()->value() === 'src/Money.php',
    )) : [];

    expect(array_map(static fn(MutationRequest $request): array => [
        array_map(static fn(Path $file): string => $file->value(), [...$request->files()]),
        [...$request->narrowing()->part()->made()],
    ], $runner->requests()))->toBe([[['src/Money.php'], LeftAtTheDeadline::LEFT]])
        ->and($left instanceof ShardResult ? leftoverRunStatuses(LeftAtTheDeadline::mutantsOf($left)) : $left)
        ->toBe(['GreaterThan-16 survived', 'Minus-21 uncovered'])
        ->and($left instanceof ShardResult && $left->units()->equals($whole->units()))->toBeTrue()
        ->and($money === [] ? [] : leftoverRunStatuses($money[0]->mutants()))
        ->toBe(leftoverRunStatuses(array_filter(
            LeftAtTheDeadline::mutantsOf($whole),
            static fn(Mutant $mutant): bool => $mutant->location()->file()->value() === 'src/Money.php',
        )))
        ->and(is_file(sprintf('%s/%s', $project, Workspace::result(Workspace::results(), ShardId::of(1))->value())))->toBeTrue();
});

it('keeps only its share\'s mutants and flaky ones where its runner makes every mutant of the files', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    $share = LeftoverShare::of(ShardId::of(1), 2, ...LeftAtTheDeadline::listed($whole, 'Minus-21'));

    new Running(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings(), Flows::setup())
        ->judging($share)
        ->run($plan, ShardId::of(1), Workspace::results());
    $left = ShardResultFile::decode((string) file_get_contents(sprintf('%s/%s', $project, LeftoverParts::resultOf(Workspace::results(), $share)->value())));

    expect($left instanceof ShardResult ? array_map(
        static fn(Mutant $mutant): MutantId => $mutant->id(),
        LeftAtTheDeadline::mutantsOf($left),
    ) : $left)->toEqual([...$share->mutants()])
        ->and($left instanceof ShardResult ? $left->flaky()->without($share->mutants())->count() : -1)->toBe(0);
});

it('judges a share of a shard whose plan pulls its work by that shard alone, pulling nothing', function (): void {
    $pulled = Planned::of(...array_map(
        static fn(Shard $shard): Shard => $shard->workedBy($shard->id()),
        [...Planned::twoShards()->shards()],
    ));
    [$project, $plan, $whole] = LeftAtTheDeadline::runOf($pulled);
    $share = LeftoverShare::of(ShardId::of(1), 1, ...LeftAtTheDeadline::listed($whole, ...LeftAtTheDeadline::LEFT));

    $left = new Running(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings(), Flows::setup())
        ->judging($share)
        ->run($plan, ShardId::of(1), Workspace::results());

    expect($plan->shards()->pulls())->toBeTrue()
        ->and($left)->toBeInstanceOf(ShardLeft::class)
        ->and(is_file(sprintf('%s/%s', $project, LeftoverParts::resultOf(Workspace::results(), $share)->value())))->toBeTrue();
});
