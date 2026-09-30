<?php

declare(strict_types=1);

namespace Tests\Reach;

trait ReachAsserts
{
    public function testComesFromATraitInAnotherTestFile(): void
    {
        self::assertIsInt(5);
    }
}
