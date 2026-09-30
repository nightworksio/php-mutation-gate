<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in proof store by the name a config chooses it by', function (BuiltinStore $builtin): void {
    expect($builtin->named())->toEqual(Name::of($builtin->value));
})->with(BuiltinStore::cases());
