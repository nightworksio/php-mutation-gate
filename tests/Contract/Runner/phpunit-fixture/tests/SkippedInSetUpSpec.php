<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quirks\Tally;

/** A test set aside in `setUp`, after it counted, before PHPUnit calls it prepared. */
final class SkippedInSetUpSpec extends TestCase
{
    protected function setUp(): void
    {
        new Tally()->count();

        self::markTestSkipped('a project skips where it cannot run');
    }

    #[Test]
    public function countsWhereItCan(): void
    {
        self::assertTrue(true);
    }
}
