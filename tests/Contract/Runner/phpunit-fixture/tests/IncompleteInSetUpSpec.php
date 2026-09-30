<?php

declare(strict_types=1);

namespace Tests;

use Library\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A test set aside in `setUp`, after it counted, before PHPUnit calls it prepared. */
final class IncompleteInSetUpSpec extends TestCase
{
    protected function setUp(): void
    {
        new Money()->count();

        self::markTestIncomplete('a project has not finished it');
    }

    #[Test]
    public function countsWhereItCan(): void
    {
        self::assertTrue(true);
    }
}
