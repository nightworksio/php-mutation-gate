<?php

declare(strict_types=1);

namespace Tests\Reach;

use Library\Reach;

final class ReachZInheritedSpec extends ReachABase
{
    public function testUsesItsBaseInAnotherTestFile(): void
    {
        self::assertIsInt(Reach::amount($this->amount()));
    }
}
