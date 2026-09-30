<?php

declare(strict_types=1);

namespace Tests\Reach;

use PHPUnit\Framework\TestCase;

abstract class ReachABase extends TestCase
{
    protected function amount(): int
    {
        return 5;
    }
}

final class ReachABaseSpec extends ReachABase
{
    public function testDeclaresTheBaseAnotherTestFileExtends(): void
    {
        self::assertSame(5, $this->amount());
    }
}
