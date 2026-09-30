<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;
use NightWorksIO\MutationGate\Core\Php\Unnamed;

it('is a constant, a case, a property or a parameter of an owner, by the owner as PHP compares it', function (): void {
    $constant = Symbol::constant('\App\Money', 'RATE');

    expect($constant->kind())->toBe(SymbolKind::Constant)
        ->and($constant->owner())->toBe('app\money')
        ->and($constant->name())->toBe('RATE')
        ->and($constant->described())->toBe('constant \App\Money::RATE')
        ->and(Symbol::enumCase('App\Status', 'Paid')->kind())->toBe(SymbolKind::EnumCase)
        ->and(Symbol::property('App\Money', 'count', static: true)->kind())->toBe(SymbolKind::StaticProperty)
        ->and(Symbol::property('App\Money', 'cents', static: false)->kind())->toBe(SymbolKind::Property)
        ->and(Symbol::parameter('App\tax', 'rate')->described())->toBe('function parameter App\tax::rate');
});

it('is unnamed where nothing named owns it, and names only its kind', function (): void {
    expect(Symbol::constant(Nameless::code(), 'RATE'))->toEqual(Unnamed::of(SymbolKind::Constant))
        ->and(Symbol::enumCase(Nameless::code(), 'Paid'))->toEqual(Unnamed::of(SymbolKind::EnumCase))
        ->and(Symbol::property(Nameless::code(), 'cents', static: false))->toEqual(Unnamed::of(SymbolKind::Property))
        ->and(Unnamed::of(SymbolKind::ClosureParameter)->kind())->toBe(SymbolKind::ClosureParameter)
        ->and(Unnamed::of(SymbolKind::ClosureParameter)->described())->toBe('closure parameter');
});
