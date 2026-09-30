<?php

declare(strict_types=1);

namespace Tests;

use Library\Money;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IsolatedSpec extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function addsInAProcessOfItsOwn(): void
    {
        self::assertSame(5, new Money()->add(2, 3));
    }
}
