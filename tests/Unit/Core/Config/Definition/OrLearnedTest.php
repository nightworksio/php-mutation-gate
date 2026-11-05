<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Definition\OrLearned;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;

it('keeps every setting under the value it wraps', function (): void {
    $stores = Adapter::choosing(Builtins::stores(ProjectRoot::origin()));

    expect($stores->effects())->not->toBe([])
        ->and(OrLearned::of($stores)->effects())->toBe($stores->effects());
});
