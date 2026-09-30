<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Signature;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;
use NightWorksIO\MutationGate\Core\Php\Unnamed;

it('names a plain function\'s parameter default, and only the kind of a method\'s or closure\'s', function (): void {
    expect(Signature::ofFunction('App\tax')->defaultOf('rate'))->toEqual(Symbol::parameter('App\tax', 'rate'))
        ->and(Signature::ofMethod()->defaultOf('rate'))->toEqual(Unnamed::of(SymbolKind::MethodParameter))
        ->and(Signature::ofClosure()->defaultOf('rate'))->toEqual(Unnamed::of(SymbolKind::ClosureParameter));
});
