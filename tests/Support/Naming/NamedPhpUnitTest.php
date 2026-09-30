<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support\Naming;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A PHPUnit test class the plugin names, never run: one test by its prefix, one by `#[Test]`, and a helper. */
final class NamedPhpUnitTest extends TestCase
{
    public function testAdds(): void
    {
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function subtracts(): void
    {
        $this->addToAssertionCount(1);
    }

    public function helper(): int
    {
        return 1;
    }
}
