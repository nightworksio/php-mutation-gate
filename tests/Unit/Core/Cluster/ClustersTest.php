<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cluster\ClusterKind;
use NightWorksIO\MutationGate\Core\Cluster\Clusters;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Tests\Support\Clustered;

it('gathers the clusters its mutants carry, by their representatives\' file, line and id', function (): void {
    $clusters = Clusters::of(Clustered::survivors()->clustered(Uncovered::Count, Clustered::sources()));
    [$expression, $gap] = iterator_to_array($clusters, preserve_keys: false);

    expect($clusters)->toHaveCount(2)
        ->and($expression->kind())->toBe(ClusterKind::Expression)
        ->and($expression->members())->toHaveCount(3)
        ->and($gap->kind())->toBe(ClusterKind::Gap)
        ->and($gap->representative()->mutant()->location()->start()->number())->toBe(16)
        ->and(Clusters::of(Clustered::survivors()))->toHaveCount(0);
});

it('orders clusters by file before line', function (): void {
    $basket = static fn(JudgedMutant $judged): JudgedMutant => JudgedMutant::of(
        Mutant::of(
            MutantId::hash(Path::of('src/Basket.php'), 'M', $judged->mutant()->mutation()->diff(), $judged->mutant()->location()->start()->number()),
            'native',
            Location::of(Path::of('src/Basket.php'), $judged->mutant()->location()->start(), $judged->mutant()->location()->start()),
            $judged->mutant()->mutation(),
            $judged->mutant()->status(),
            $judged->mutant()->duration(),
        ),
        $judged->judgement(),
    )->judgedBy($judged->tests());
    $inBasket = JudgedMutants::of(...array_map($basket, Clustered::listed(Clustered::removedCalls())));
    $sources = Clustered::sources()->with(Path::of('src/Basket.php'), Contents::of(Clustered::CART));
    $clusters = Clusters::of($inBasket->and(Clustered::comparisons())->clustered(Uncovered::Count, $sources));

    expect(array_map(
        static fn(Cluster $cluster): string => $cluster->representative()->mutant()->location()->file()->value(),
        iterator_to_array($clusters, preserve_keys: false),
    ))->toBe(['src/Basket.php', 'src/Cart.php']);
});

it('orders clusters of one file by line', function (): void {
    $clusters = Clusters::of(Clustered::removedCalls()->and(Clustered::comparisons())->clustered(Uncovered::Count, Clustered::sources()));

    expect(array_map(
        static fn(Cluster $cluster): int => $cluster->representative()->mutant()->location()->start()->number(),
        iterator_to_array($clusters, preserve_keys: false),
    ))->toBe([7, 16]);
});

it('finds the cluster a mutant is in, and none for a mutant in none', function (): void {
    $clustered = Clustered::survivors()->clustered(Uncovered::Count, Clustered::sources());
    $clusters = Clusters::of($clustered);
    $mutants = Clustered::listed($clustered);
    $inGap = $clusters->clusterOf($mutants[5]);

    expect($clusters->clusterOf($mutants[3]))->toEqual(Unclustered::mutant())
        ->and($inGap)->toBeInstanceOf(Cluster::class)
        ->and($inGap instanceof Cluster ? $inGap->kind() : ClusterKind::Expression)->toBe(ClusterKind::Gap)
        ->and($clusters->clusterOf($mutants[0]))->toBe($clusters->clusterOf($mutants[1]));
});
