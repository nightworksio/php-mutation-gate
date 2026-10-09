<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Migration\BuilderCall;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\NotGiven;

it('names a builder class and its method, and tells a method of the chain from a static call', function (): void {
    $static = BuilderCall::of('Reach::everything');
    $chain = BuilderCall::of('Gate::newCode');

    expect([$static->className(), $static->method(), $static->isOnTheChain(), $static->spelt()])
        ->toBe(['Reach', 'everything', false, 'Reach::everything'])
        ->and([$chain->className(), $chain->method(), $chain->isOnTheChain()])->toBe(['Gate', 'newCode', true])
        ->and(BuilderCall::of('Reach')->method())->toBe('');
});

it('retires a call for another with the same arguments, or for nothing', function (): void {
    $replacing = Spelling::replacing('Reach::everywhere', 'Reach::everything');
    $retiring = Spelling::retiring('Gate::legacy');

    expect([$replacing->retired()->spelt(), $replacing->replacement() instanceof BuilderCall ? $replacing->replacement()->spelt() : ''])
        ->toBe(['Reach::everywhere', 'Reach::everything'])
        ->and($retiring->retired()->spelt())->toBe('Gate::legacy')
        ->and($retiring->replacement())->toEqual(NotGiven::value());
});
