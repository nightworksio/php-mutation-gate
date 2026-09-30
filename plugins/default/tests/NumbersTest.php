<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGateDefault\Numbers;
use PhpParser\Node\DeclareItem;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;

it('moves an integer below the largest, and outside a declare', function (): void {
    $declared = new Int_(1);
    $declared->setAttribute(Mutator::PARENT, new DeclareItem('strict_types', $declared));

    expect(Numbers::canMove(new Int_(3)))->toBeTrue()
        ->and(Numbers::canMove(new Int_(PHP_INT_MAX - 1)))->toBeTrue()
        ->and(Numbers::canMove(new Int_(PHP_INT_MAX)))->toBeFalse()
        ->and(Numbers::canMove($declared))->toBeFalse();
});

it('makes an integer one more or one less, keeping how it is written', function (): void {
    $hex = new Int_(16, ['kind' => Int_::KIND_HEX]);

    expect(Numbers::moreInteger(new Int_(3))->value)->toBe(4)
        ->and(Numbers::lessInteger(new Int_(3))->value)->toBe(2)
        ->and(Numbers::moreInteger($hex)->getAttribute('kind'))->toBe(Int_::KIND_HEX);
});

it('moves the literal the other way under a unary minus, so the number moves the right way', function (): void {
    $negative = new Int_(3);
    $negative->setAttribute(Mutator::PARENT, new UnaryMinus($negative));

    expect(Numbers::moreInteger($negative)->value)->toBe(2)
        ->and(Numbers::lessInteger($negative)->value)->toBe(4);
});

it('makes a float one more or one less', function (): void {
    expect(Numbers::moreFloat(new Float_(1.5))->value)->toBe(2.5)
        ->and(Numbers::lessFloat(new Float_(1.5))->value)->toBe(0.5)
        ->and(Numbers::moreFloat(new Float_(1.5, ['kind' => 1]))->getAttribute('kind'))->toBe(1);
});
