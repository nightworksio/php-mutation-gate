<?php

declare(strict_types=1);

use Library\Shapes;
use NightWorksIO\MutationGate\Attribute\Holds;

// Every shape of Pest test that holds src/Shapes.php (ADR-0004, decision 7).
// Each says it ran through seen(), with a label of its own, and asserts
// nothing a mutant of src/Shapes.php changes.

it('is held with function', #[Holds('src/Shapes.php')] function (): void {
    expect(seen('it function'))->toBeBool();
});

it('is held with fn', #[Holds('src/Shapes.php')] fn() => expect(seen('it fn'))->toBeBool());

test('is held by test with function', #[Holds('src/Shapes.php')] function (): void {
    expect(seen('test function'))->toBeBool();
});

test('is held by test with fn', #[Holds('src/Shapes.php')] fn() => expect(seen('test fn'))->toBeBool());

arch('is held by arch with function', #[Holds('src/Shapes.php')] function (): void {
    expect(seen('arch function'))->toBeBool();
});

arch('is held by arch with fn', #[Holds('src/Shapes.php')] fn() => expect(seen('arch fn'))->toBeBool());

it('is held by a class constant', #[Holds(Shapes::PATH)] function (): void {
    expect(seen('constant'))->toBeBool();
});

it('is held twice', #[Holds('src/Shapes.php'), Holds('src/Legacy.php')] function (): void {
    expect(seen('twice'))->toBeBool();
});

it('is held in every row', #[Holds('src/Shapes.php')] function (int $row): void {
    expect(seen(sprintf('row %d', $row)))->toBeBool();
})->with([1, 2, 3]);

describe('a held describe', #[Holds('src/Shapes.php')] function (): void {
    it('is held by its describe', function (): void {
        expect(seen('describe'))->toBeBool();
    });

    describe('a nested describe', function (): void {
        it('is held by the describe around it', function (): void {
            expect(seen('nested describe'))->toBeBool();
        });
    });

    it('is held as a higher-order test')->expect(fn(): bool => seen('higher order'))->toBeBool();

    it('is held and skipped', function (): void {
        expect(seen('skipped'))->toBeBool();
    })->skip('a skipped test is selected and runs nothing');
});
