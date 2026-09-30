<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in runner by the name a config chooses it by', function (BuiltinRunner $builtin): void {
    expect($builtin->named())->toEqual(Name::of($builtin->value));
})->with(BuiltinRunner::cases());
