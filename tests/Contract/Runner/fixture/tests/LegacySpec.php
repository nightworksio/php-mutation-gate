<?php

declare(strict_types=1);

use Legacy\Legacy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// A class in no namespace: its id has no backslash, so Pest's filter cannot
// name it, and Pest would call the mutant it covers uncovered.
final class LegacySpec extends TestCase
{
    #[Test]
    public function decrements(): void
    {
        expect(new Legacy()->decrement(3))->toBe(2);
    }
}
