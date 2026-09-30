<?php

declare(strict_types=1);

namespace Tests;

use Library\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A class of tests set aside before any of them runs. */
final class SkippedBeforeClassSpec extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        new Money()->count();

        self::markTestSkipped('a project skips a whole class');
    }

    #[Test]
    public function countsWhereItCan(): void
    {
        self::assertTrue(true);
    }

    #[Test]
    public function countsAgainWhereItCan(): void
    {
        self::assertTrue(true);
    }
}
