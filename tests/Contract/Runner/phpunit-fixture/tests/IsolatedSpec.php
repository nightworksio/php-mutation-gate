<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quirks\Tally;

final class IsolatedSpec extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function addsInAProcessOfItsOwn(): void
    {
        self::assertSame(5, new Tally()->add(2, 3));
    }
}
