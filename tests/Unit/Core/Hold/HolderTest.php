<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\Holder;

it('tells a method from a class by the separator between them', function (): void {
    expect(Holder::of('Tests\KernelTest::testBoots')->isMethod())->toBeTrue()
        ->and(Holder::of('Tests\KernelTest')->isMethod())->toBeFalse();
});

it('names in a filter every test of a class, and a method with its data sets alone', function (): void {
    expect(Holder::of('Tests\KernelTest')->filtered())->toBe('Tests\\\\KernelTest::')
        ->and(Holder::of('Tests\KernelTest::testBoots')->filtered())->toBe('Tests\\\\KernelTest\:\:testBoots\b');
});

it('keeps the holder as it is written', function (): void {
    expect(Holder::of('Tests\KernelTest::testBoots')->written())->toBe('Tests\KernelTest::testBoots');
});
