<?php

declare(strict_types=1);

namespace Tests;

use function class_exists;
use function getenv;

use Library\Money;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function touch;

// A test that depends on another, which PHPUnit skips unless the other runs
// before it in the same run. It leaves CONTRACT_RAN, where the variable names
// a file, only when it runs with what the other gives it. It loads
// src/Money.php, as a control of that file serves it, and runs none of its
// lines, so no mutant's tests change.
final class DependsSpec extends TestCase
{
    #[Test]
    public function makesAValue(): int
    {
        return 7;
    }

    #[Test]
    #[Depends('makesAValue')]
    public function marksWhenItRunsWithIt(int $made): void
    {
        if (getenv('CONTRACT_RAN') !== false) {
            touch((string) getenv('CONTRACT_RAN'));
        }

        self::assertSame(7, $made);
        self::assertTrue(class_exists(Money::class));
    }
}
