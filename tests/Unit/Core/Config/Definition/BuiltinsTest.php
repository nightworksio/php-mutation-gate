<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\BuiltinTreeSource;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;

it('checks the options of every built-in runner, tree source, store and CI plan', function (): void {
    $origin = ProjectRoot::origin();
    $has = static fn(Builtins $builtins, BackedEnum ...$cases): bool => array_all(
        $cases,
        static fn(BackedEnum $case): bool => $builtins->has(sprintf('%s', $case->value)),
    );

    expect($has(Builtins::runners($origin), ...BuiltinRunner::cases()))->toBeTrue()
        ->and($has(Builtins::treeSources($origin), ...BuiltinTreeSource::cases()))->toBeTrue()
        ->and($has(Builtins::stores($origin), ...BuiltinStore::cases()))->toBeTrue()
        ->and($has(Builtins::ciPlans($origin), ...BuiltinCiPlan::cases()))->toBeTrue();
});
