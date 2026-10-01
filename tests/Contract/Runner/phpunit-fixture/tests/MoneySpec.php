<?php

declare(strict_types=1);

namespace Tests;

use function double;

use Library\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneySpec extends TestCase
{
    #[Test]
    public function addsTwoAmounts(): void
    {
        self::assertSame(5, new Money()->add(2, 3));
    }

    /** @return array<string, array{int, int, int}> a row whose name PHPUnit cannot read back from a line of a file */
    public static function pairs(): array
    {
        return ["one\nplus one" => [1, 1, 2]];
    }

    #[Test]
    #[DataProvider('pairs')]
    public function addsEachPair(int $a, int $b, int $sum): void
    {
        self::assertSame($sum, new Money()->add($a, $b));
    }

    #[Test]
    public function countsWithoutSayingSo(): void
    {
        new Money()->count();

        self::assertTrue(true);
    }

    #[Test]
    public function drainsAnAmountToNothing(): void
    {
        self::assertSame(0, new Money()->drain(3));
    }

    #[Test]
    public function doublesThroughAFunctionComposerLoads(): void
    {
        self::assertSame(6, double(3));
    }
}
