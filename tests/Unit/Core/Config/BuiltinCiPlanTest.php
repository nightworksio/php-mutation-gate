<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in CI plan by the name a config chooses it by', function (BuiltinCiPlan $builtin): void {
    expect($builtin->named())->toEqual(Name::of($builtin->value));
})->with(BuiltinCiPlan::cases());
