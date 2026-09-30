<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinTreeSource;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in tree source by the name a config chooses it by', function (BuiltinTreeSource $builtin): void {
    expect($builtin->named())->toEqual(Name::of($builtin->value));
})->with(BuiltinTreeSource::cases());
