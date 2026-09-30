<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\HeldPath;
use NightWorksIO\MutationGate\Core\Hold\Holder;
use NightWorksIO\MutationGate\Core\Hold\Holding;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttribute;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttributes;
use NightWorksIO\MutationGate\Core\Hold\Standing;

it('holds no attribute to begin with', function (): void {
    expect(HoldsAttributes::none())->toHaveCount(0)
        ->and(iterator_to_array(HoldsAttributes::none(), preserve_keys: false))->toBe([])
        ->and(HoldsAttributes::none()->holdings())->toEqual(Holdings::none());
});

it('keeps every attribute in the order it is written', function (): void {
    $first = HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/Kernel.php'), 5);
    $second = HoldsAttribute::onFunction(HeldPath::literal('src/Http'), 9, 'helper');
    $attributes = HoldsAttributes::none()->with($first)->with($second);

    expect($attributes)->toHaveCount(2)
        ->and(iterator_to_array($attributes, preserve_keys: false))->toBe([$first, $second]);
});

it('declares a holding for each attribute on a class or method, and for nothing else', function (): void {
    $attributes = HoldsAttributes::none()
        ->with(HoldsAttribute::onClass(HeldPath::literal('src/Kernel.php'), 7, 'Tests\KernelTest', grouped: false))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/Boot.php'), 12))
        ->with(HoldsAttribute::onFunction(HeldPath::literal('src/Boot.php'), 15, 'Tests\helper'))
        ->with(HoldsAttribute::onMethod(HeldPath::expression('self::HTTP'), 20, 'Tests\HttpTest::testIt', grouped: true));

    expect($attributes->holdings())->toEqual(Holdings::none()
        ->with(Holding::byAttribute('src/Kernel.php', Holder::of('Tests\KernelTest')))
        ->with(Holding::byAttribute('self::HTTP', Holder::of('Tests\HttpTest::testIt'))));
});
