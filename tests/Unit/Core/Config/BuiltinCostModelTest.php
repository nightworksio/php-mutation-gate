<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinCostModel;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in cost model by the name the registry holds it by', function (): void {
    foreach (BuiltinCostModel::cases() as $builtin) {
        expect($builtin->named())->toEqual(Name::of($builtin->value));
    }
});
