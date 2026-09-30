<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\Cluster\ClusterKind;
use NightWorksIO\MutationGate\Core\Cluster\Membership;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

it('holds the cluster a survivor is in and the rule that put it there', function (): void {
    $id = ClusterId::of(MutantIds::of(MutantId::hash(Path::of('src/Cart.php'), 'M', '-a', 0)));
    $membership = Membership::of($id, ClusterKind::Gap);

    expect($membership->id())->toBe($id)
        ->and($membership->kind())->toBe(ClusterKind::Gap);
});
