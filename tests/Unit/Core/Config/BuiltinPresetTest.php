<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinPreset;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in preset by the name a config chooses it by', function (): void {
    foreach (BuiltinPreset::cases() as $builtin) {
        expect($builtin->named())->toEqual(Name::of($builtin->value));
    }
});
