<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Report\Folded;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Tests\Support\Clustered;

it('shows each cluster once, where its first member met stands, and every other mutant as it comes', function (): void {
    $trees = Clustered::verdict()->trees();
    $mutants = Clustered::listed($trees->mutants());
    $items = Folded::of([$mutants[3], $mutants[5], $mutants[0], $mutants[4], $mutants[1], $mutants[6], $mutants[2]], $trees->clusters());

    expect(array_map(
        static fn(JudgedMutant|Cluster $item): string => $item instanceof Cluster
            ? sprintf('%s at %d', $item->kind()->value, $item->representative()->mutant()->location()->start()->number())
            : sprintf('mutant at %d', $item->mutant()->location()->start()->number()),
        Folded::of(Clustered::listed($trees->mutants()), $trees->clusters()),
    ))->toBe(['expression at 7', 'mutant at 11', 'gap at 16'])
        ->and(array_map(static fn(JudgedMutant|Cluster $item): string => $item instanceof Cluster ? $item->kind()->value : 'mutant', $items))
        ->toBe(['mutant', 'gap', 'expression'])
        ->and(Folded::of([], $trees->clusters()))->toBe([]);
});
