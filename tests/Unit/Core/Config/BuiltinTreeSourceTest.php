<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinTreeSource;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in tree source by the name a config chooses it by', function (): void {
    foreach (BuiltinTreeSource::cases() as $builtin) {
        expect($builtin->named())->toEqual(Name::of($builtin->value));
    }
});
