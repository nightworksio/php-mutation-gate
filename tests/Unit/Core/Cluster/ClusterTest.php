<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\Cluster\ClusterKind;
use NightWorksIO\MutationGate\Core\Cluster\Membership;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Tests\Support\Clustered;

it('orders its members by line then id, the first its representative', function (): void {
    [$first, $second, $third] = Clustered::listed(Clustered::comparisons());
    $lone = Clustered::lone();
    $membership = Membership::of(ClusterId::of(MutantIds::of($lone->mutant()->id())), ClusterKind::Gap);
    $byId = [$first, $second, $third];
    usort($byId, static fn(JudgedMutant $one, JudgedMutant $other): int => strcmp($one->mutant()->id()->value(), $other->mutant()->id()->value()));
    $cluster = Cluster::of($membership, $lone, $third, $first, $second);

    expect(array_map(static fn(JudgedMutant $member): string => $member->mutant()->id()->value(), iterator_to_array($cluster->members(), preserve_keys: false)))
        ->toBe([...array_map(static fn(JudgedMutant $member): string => $member->mutant()->id()->value(), $byId), $lone->mutant()->id()->value()])
        ->and($cluster->representative())->toBe($byId[0])
        ->and($cluster->id())->toBe($membership->id())
        ->and($cluster->kind())->toBe(ClusterKind::Gap);
});

it('writes the commands that stub and explain it by its id', function (): void {
    $membership = Membership::of(ClusterId::of(MutantIds::of(Clustered::lone()->mutant()->id())), ClusterKind::Expression);
    $cluster = Cluster::of($membership, Clustered::lone());

    expect($cluster->stub(GateCommand::installed()))->toBe(sprintf('vendor/bin/mutation-gate stub %s', $membership->id()->value()))
        ->and($cluster->explain(GateCommand::installed()))->toBe(sprintf('vendor/bin/mutation-gate explain %s', $membership->id()->value()));
});

it('is on a changed line where any member is', function (): void {
    $membership = Membership::of(ClusterId::of(MutantIds::of(Clustered::lone()->mutant()->id())), ClusterKind::Gap);
    [$changed] = Clustered::listed(Clustered::removedCalls());

    expect(Cluster::of($membership, Clustered::lone(), $changed)->isOnChangedLine())->toBeTrue()
        ->and(Cluster::of($membership, $changed, Clustered::lone())->isOnChangedLine())->toBeTrue()
        ->and(Cluster::of($membership, Clustered::lone())->isOnChangedLine())->toBeFalse();
});

it('takes the same representative and order of members on one line in whatever order they arrive, their ids in byte order however they read', function (): void {
    $ids = ['105436102654', '06270e550940', '0a1b2c3d4e5f', '097b2ebee2d4'];
    $members = array_map(static fn(string $id): JudgedMutant => Clustered::withId($id, 7), $ids);
    $membership = Membership::of(ClusterId::of(MutantIds::of(...array_map(static fn(JudgedMutant $member): MutantId => $member->mutant()->id(), $members))), ClusterKind::Expression);
    $orders = [];

    foreach ([[0, 1, 2, 3], [3, 2, 1, 0], [1, 3, 0, 2], [2, 0, 3, 1], [0, 2, 1, 3], [3, 1, 2, 0], [1, 0, 3, 2], [2, 3, 0, 1]] as $order) {
        $arrived = array_map(static fn(int $at): JudgedMutant => $members[$at], $order);
        $cluster = Cluster::of($membership, $arrived[0], ...array_slice($arrived, 1));
        $orders[] = array_map(static fn(JudgedMutant $member): string => $member->mutant()->id()->value(), iterator_to_array($cluster->members(), preserve_keys: false));
    }

    expect(array_values(array_unique(array_map(static fn(array $order): string => implode(' ', $order), $orders))))
        ->toBe(['06270e550940 097b2ebee2d4 0a1b2c3d4e5f 105436102654']);
});
