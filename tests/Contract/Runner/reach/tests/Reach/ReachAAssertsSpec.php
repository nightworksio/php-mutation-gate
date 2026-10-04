<?php

declare(strict_types=1);

namespace Tests\Reach;

use Library\Reach;
use PHPUnit\Framework\TestCase;

use function trait_exists;

trait ReachAAsserts
{
    public function testComesFromATraitInAnotherTestFile(): void
    {
        self::assertIsInt(Reach::amount(5));
    }
}

final class ReachAAssertsSpec extends TestCase
{
    public function testDeclaresTheTraitAnotherTestFileUses(): void
    {
        self::assertTrue(trait_exists(ReachAAsserts::class));
    }
}
