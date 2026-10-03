<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Proof\Records;
use NightWorksIO\MutationGate\Core\Report\Explanation;
use NightWorksIO\MutationGate\Core\Report\Explanations;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Explained;

it('holds one mutant explained, in no cluster, as its first', function (): void {
    $explanation = Explanation::recorded(
        JudgedMutant::of(Explained::mutant(MutantStatus::Survived), MutantJudgement::Survived),
        CannotTell::because('Not run.'),
        Records::none(IdPrefix::of(Explained::id())),
    );
    $explained = Explanations::ofMutant($explanation);

    expect($explained->first())->toBe($explanation)
        ->and($explained->cluster())->toBeInstanceOf(Unclustered::class)
        ->and(iterator_to_array($explained, preserve_keys: false))->toBe([$explanation]);
});

it('holds a cluster with each member, its first survivor first', function (): void {
    $explained = Explained::cluster();
    $cluster = $explained->cluster();

    expect($cluster)->toBeInstanceOf(Cluster::class)
        ->and($explained->first()->mutant())->toBe($cluster instanceof Cluster ? $cluster->representative() : $cluster)
        ->and(iterator_count($explained))->toBe(3);
});
