<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;

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

it('can be followed where its kind can and something named owns it', function (): void {
    expect(Symbol::constant('App\Money', 'RATE')->isFollowable())->toBeTrue()
        ->and(Symbol::constant('', 'RATE')->isFollowable())->toBeFalse()
        ->and(Symbol::unnamed(SymbolKind::AttributeArgument)->isFollowable())->toBeFalse()
        ->and(Symbol::unnamed(SymbolKind::ClosureParameter)->described())->toBe('closure parameter');
});
