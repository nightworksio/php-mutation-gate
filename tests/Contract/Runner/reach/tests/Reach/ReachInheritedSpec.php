<?php

declare(strict_types=1);

namespace Tests\Reach;

final class ReachInheritedSpec extends ReachBaseSpec
{
    public function testUsesItsBaseInAnotherTestFile(): void
    {
        self::assertIsInt($this->amount());
    }
}
