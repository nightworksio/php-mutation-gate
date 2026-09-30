<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Signature;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;

it('names a plain function\'s parameter default, and only the kind of a method\'s or closure\'s', function (): void {
    expect(Signature::ofFunction('App\tax')->defaultOf('rate'))->toEqual(Symbol::parameter('App\tax', 'rate'))
        ->and(Signature::ofMethod()->defaultOf('rate'))->toEqual(Symbol::unnamed(SymbolKind::MethodParameter))
        ->and(Signature::ofClosure()->defaultOf('rate'))->toEqual(Symbol::unnamed(SymbolKind::ClosureParameter));
});
