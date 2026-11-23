<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\LeftoverParts;
use NightWorksIO\MutationGate\Cli\Flow\LeftoverScope;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\ShardLeft;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Config\Planning;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\PlanLearned;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Share;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
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

it('says which mutants a share lists that its run did not judge, leaving each as its shard left it', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    $unmade = LeftMutant::of(MutantId::hash(Path::of('src/Money.php'), 'Minus', 'nowhere', 99), 'nowhere', Path::of('src/Money.php'));
    $share = LeftoverShare::of(ShardId::of(1), 2, ...[...LeftAtTheDeadline::listed($whole, 'Minus-21'), $unmade]);

    new Running(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings(), Flows::setup())
        ->judging($share)
        ->run($plan, ShardId::of(1), Workspace::results());
    $left = ShardResultFile::decode((string) file_get_contents(sprintf('%s/%s', $project, LeftoverParts::resultOf(Workspace::results(), $share)->value())));
    $said = $left instanceof ShardResult ? array_map(
        static fn(Warning $warning): string => $warning->text(),
        [...$left->warnings()],
    ) : [];

    expect($said)->toContain(sprintf(
        'Share 1.2 lists 1 mutants its run did not judge, the first %s: each stays as shard 1 left it, unjudged.',
        $unmade->id()->value(),
    ))
        ->and($left instanceof ShardResult ? count(LeftAtTheDeadline::mutantsOf($left)) : -1)->toBe(1);
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

it('mutates, for a share, the held directory its mutants\' files lie in, as well as the files that are units of their own', function (): void {
    $held = Unit::held(Path::of('bootstrap/Composition'), Group::named('holds:bootstrap/Composition'));
    $shard = Shard::of(
        ShardId::of(57),
        Package::at(Path::root()),
        Units::of(Unit::file(Path::of('bridge/src/Call.php')), $held, Unit::file(Path::of('bridge/src/Clock.php'))),
        Seconds::of(654.0),
        'wayfinding, composition and bridge',
    );
    $left = static fn(string $file, string $native): LeftMutant => LeftMutant::of(
        MutantId::hash(Path::of($file), 'Minus', $native, 1),
        $native,
        Path::of($file),
    );
    $share = LeftoverShare::of(ShardId::of(57), 2, $left('bootstrap/Composition/TheWayToEveryStack.php', 'a'), $left('bridge/src/Clock.php', 'b'));

    expect(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...LeftoverScope::of($share)->shardOf($shard)->units()]))
        ->toBe(['bootstrap/Composition', 'bridge/src/Clock.php'])
        ->and([...LeftoverScope::of(new NotGiven())->shardOf($shard)->units()])->toHaveCount(3);
});

it('runs the tail of the plan\'s jobs where the share\'s job runs its own, and leaves what the plan learned as it is otherwise', function (): void {
    $learned = PlanLearned::none()->tailing(JobTail::of(Seconds::minutes(60), 20));
    $share = LeftoverShare::of(ShardId::of(1), 1);

    expect(LeftoverScope::of($share->runningItsTail())->learned($learned)->tail()->runs())->toBeTrue()
        ->and(LeftoverScope::of($share)->learned($learned))->toBe($learned)
        ->and(LeftoverScope::of(new NotGiven())->learned($learned))->toBe($learned);
});

it('holds a first-wave job\'s run again of doubtful kills to the config\'s share, and no job of the wave after or with no wave to follow', function (): void {
    $planning = Planning::of(confirm: 0.25);
    $search = KillSearch::standard();
    $share = LeftoverShare::of(ShardId::of(1), 1);

    expect(LeftoverScope::of(new NotGiven())->confirming($search, stopsAtADeadline: true, planning: $planning)->confirmShare())->toEqual(Share::of(0.25))
        ->and(LeftoverScope::of(new NotGiven())->confirming($search, stopsAtADeadline: true, planning: Planning::of())->confirmShare())->toEqual(Share::of(Planning::CONFIRM))
        ->and(LeftoverScope::of(new NotGiven())->confirming($search, stopsAtADeadline: false, planning: $planning))->toBe($search)
        ->and(LeftoverScope::of($share)->confirming($search, stopsAtADeadline: true, planning: $planning)->confirmShare())->toEqual(Share::of(0.25))
        ->and(LeftoverScope::of($share)->confirming($search, stopsAtADeadline: false, planning: $planning))->toBe($search);
});
