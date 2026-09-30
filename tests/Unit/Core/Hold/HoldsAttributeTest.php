<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\HeldPath;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttribute;
use NightWorksIO\MutationGate\Core\Hold\Standing;

it('stands on a closure with no holder and no group', function (): void {
    $attribute = HoldsAttribute::at(Standing::DescribeClosure, HeldPath::literal('src/Http'), 12);

    expect($attribute->standing())->toBe(Standing::DescribeClosure)
        ->and($attribute->path())->toEqual(HeldPath::literal('src/Http'))
        ->and($attribute->line())->toBe(12)
        ->and($attribute->holder())->toBe('')
        ->and($attribute->isGrouped())->toBeFalse()
        ->and($attribute->written())->toBe("#[Holds('src/Http')]");
});

it('stands on a named function, which it names', function (): void {
    $attribute = HoldsAttribute::onFunction(HeldPath::expression('self::KERNEL'), 3, 'Tests\helper');

    expect($attribute->standing())->toBe(Standing::NamedFunction)
        ->and($attribute->holder())->toBe('Tests\helper')
        ->and($attribute->isGrouped())->toBeFalse()
        ->and($attribute->written())->toBe('#[Holds(self::KERNEL)]');
});

it('stands on a class or a method, with its group beside it or not', function (): void {
    $class = HoldsAttribute::onClass(HeldPath::literal('src/Kernel.php'), 7, 'Tests\KernelTest', grouped: true);
    $method = HoldsAttribute::onMethod(HeldPath::literal('src/Kernel.php'), 9, 'Tests\KernelTest::testBoots', grouped: false);

    expect($class->standing())->toBe(Standing::TestClass)
        ->and($class->holder())->toBe('Tests\KernelTest')
        ->and($class->line())->toBe(7)
        ->and($class->isGrouped())->toBeTrue()
        ->and($method->standing())->toBe(Standing::TestMethod)
        ->and($method->holder())->toBe('Tests\KernelTest::testBoots')
        ->and($method->line())->toBe(9)
        ->and($method->isGrouped())->toBeFalse();
});
